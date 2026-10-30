// Browsertest des Joomla-Plugins (Playwright, headless Chromium) gegen den Joomla-Container und das echte Pruefprojekt.
// Zeigt: Widget im Kontaktformular, Loesen des Widgets, Absenden kommt durch; ohne Widget (entfernt) wird abgelehnt;
// Registrierung zeigt das Widget; Eintrag in der Verwaltung (Plugin-Liste und Standard-Captcha).
import { chromium } from "playwright";
import { mkdirSync } from "node:fs";

const J = process.env.J_URL || "http://localhost";
const V = process.env.J_VERSION || "";
const AUFN = "/out/aufnahmen";
mkdirSync(AUFN, { recursive: true });
let fehler = 0;
const ok = (b, t) => { console.log((b ? "OK     " : "FEHLER ") + t); if (!b) fehler++; };
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1100, height: 1000 }, locale: "de-DE" });
const p = await ctx.newPage();
p.on("pageerror", (e) => console.log("Seitenfehler:", e.message.slice(0, 200)));
const text = async () => (await p.locator("body").innerText());

async function kontakt() {
  await p.goto(`${J}/index.php?option=com_contact&view=contact&id=1`);
  await p.locator("#contact-form, form.form-validate").first().waitFor();
  await p.fill("#jform_contact_name", "Max Muster");
  await p.fill("#jform_contact_email", "max@example.org");
  await p.fill("#jform_contact_emailmsg", "Betreff zum Testen");
  await p.fill("#jform_contact_message", "Eine Nachricht zum Testen des Kontaktformulars mit hostily Captcha.");
}

// ---- Kontaktformular: Widget loesen, absenden
await kontakt();
const wid = p.locator("form hostily-captcha");
ok((await wid.count()) === 1, "Kontakt: Widget im Formular");
const box = p.locator("form hostily-captcha >> role=checkbox").first();
await box.waitFor({ timeout: 30000 });
await wid.evaluate((e) => e.scrollIntoView({ block: "center" }));
await p.waitForTimeout(500);
await p.screenshot({ path: `${AUFN}/kontakt-widget-bereit-${V}.png` });
await box.click();
await p.locator("form hostily-captcha >> role=checkbox[checked]").first().waitFor({ timeout: 90000 });
const token = await p.locator("form input[name='hostily-captcha-token']").inputValue();
ok(token.length > 10, "Kontakt: Widget geloest, Token im Formular (" + token.slice(0, 8) + "...)");
await p.waitForTimeout(500);
await p.screenshot({ path: `${AUFN}/kontakt-widget-geloest-${V}.png` });
// Der Dienst lehnt zu schnelle Einsendungen ab (Mindestzeit, Grund "mindestzeit"): wie ein Mensch kurz warten
await p.waitForTimeout(10000);
await p.locator("form.form-validate button[type=submit], form.form-validate .validate").first().click();
await p.waitForLoadState("load");
await p.waitForTimeout(1500);
let t = await text();
ok(!/Spamschutz abgelehnt|rejected by the spam|kein Roboter|not a robot|nicht erreichbar/i.test(t), "Kontakt: Absenden mit geloestem Widget nicht vom Captcha abgelehnt");
await p.screenshot({ path: `${AUFN}/kontakt-abgesendet-${V}.png` });

// ---- Kontaktformular ohne Widget: abgelehnt
await kontakt();
await p.locator("form hostily-captcha").evaluate((e) => e.remove());
await p.locator("form.form-validate button[type=submit], form.form-validate .validate").first().click();
await p.waitForLoadState("load");
await p.waitForTimeout(1500);
t = await text();
ok(/kein Roboter|not a robot|Spamschutz|spam protection/i.test(t), "Kontakt: ohne Token abgelehnt, Meldung sichtbar");
await p.screenshot({ path: `${AUFN}/kontakt-abgelehnt-${V}.png` });

// ---- Registrierung: Widget vorhanden
await p.goto(`${J}/index.php?option=com_users&view=registration`);
await p.locator("form hostily-captcha >> role=checkbox").first().waitFor({ timeout: 30000 });
ok(true, "Registrierung: Widget im Formular");
await p.locator("form hostily-captcha").evaluate((e) => e.scrollIntoView({ block: "center" }));
await p.screenshot({ path: `${AUFN}/registrierung-widget-${V}.png` });

// ---- Verwaltung: Plugin in der Liste, Auswahl im Standard-Captcha
await p.goto(`${J}/administrator/index.php`);
await p.fill("#mod-login-username", "admin");
await p.fill("#mod-login-password", "Passw0rt-Test-12345");
await Promise.all([p.waitForURL(/administrator\/(index\.php)?(\?.*)?$/, { timeout: 30000 }).catch(() => {}), p.locator("#btn-login-submit").click()]);
await p.locator("#menu, .header-title").first().waitFor({ timeout: 30000 });
await p.getByText("Hide Forever").click({ timeout: 4000 }).catch(() => {});
await p.goto(`${J}/administrator/index.php?option=com_plugins&view=plugins&filter[folder]=captcha&filter[search]=hostily`);
await p.waitForLoadState("load");
ok(/hostily/i.test(await text()), "Verwaltung: Plugin \"hostily Captcha\" in der Plugin-Liste (Gruppe captcha)");
await p.screenshot({ path: `${AUFN}/verwaltung-plugin-liste-${V}.png` });
for (let i = 0; i < 4 && !(await p.locator("#jform_captcha").count()); i++) {
  await p.goto(`${J}/administrator/index.php?option=com_config`, { waitUntil: "domcontentloaded" }).catch(() => {});
  await p.waitForTimeout(2000);
}
const sel = p.locator("#jform_captcha");
const opts = (await sel.locator("option").allInnerTexts()).join("|");
ok(/hostily/i.test(opts), "Verwaltung: \"hostily Captcha\" steht im Standard-Captcha der Konfiguration");
ok((await sel.inputValue()) === "hostily", "Verwaltung: Standard-Captcha ist hostily");
await sel.evaluate((e) => e.scrollIntoView({ block: "center" }));
await p.screenshot({ path: `${AUFN}/verwaltung-standard-captcha-${V}.png` });
await browser.close();
process.exit(fehler ? 1 : 0);
