/** =========================
 * Vereinshelfer – Trainer Webapp (PIN Login)
 * Dateien: Code.gs, index.html, style.html
 * ========================= */

const CFG = {
  // Wenn das Script an das Sheet gebunden ist: leave null
  SPREADSHEET_ID: "1xDcT9tJyY5ENaxZTn2qEUHu3rcJxZwGdZROLcxEOtfs", // z.B. "1AbC..." falls Standalone Script
  SHEETS: {
    TRAINER: "TRAINER",
    TRAININGS: "TRAININGS",
    EINTEILUNGEN: "EINTEILUNGEN",
    ABMELDUNGEN: "ABMELDUNGEN",
    TRAININGSPLAN: "TRAININGSPLAN",
    ROLLEN_SAETZE: "ROLLEN_SAETZE",
    TURNIERE: "TURNIERE",
    TURNIER_EINSAETZE: "TURNIER_EINSAETZE",
    FAHRTEN: "FAHRTEN",
  },
  SESSION_TTL_SECONDS: 60 * 60 * 8, // 8h
  TIMEZONE: Session.getScriptTimeZone(),
};

let _cachedSs = null;
let __TEMPLATE_DATA__ = null;

const ALLOWED_INCLUDES = new Set([
  "index",
  "style",
  "ui_components",
  "ui_app",
  "ui_trainings",
  "ui_einsaetze",
  "ui_abrechnung",
  "ui_admin",
  "ui_turniere",
  "ui_mehr",
]);

/** ====== UI ====== */
function doGet(e) {
  const template = HtmlService.createTemplateFromFile("index");
  template.data = sanitizeParams_(e);
  __TEMPLATE_DATA__ = template.data;
  return template
    .evaluate()
    .setTitle("Vereinshelfer – Trainer")
    .setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL);
}

function include(filename) {
  const name = String(filename || "").trim();
  try {
    if (!ALLOWED_INCLUDES.has(name)) {
      return `<!-- include blocked: ${name} -->`;
    }

    const template = HtmlService.createTemplateFromFile(name);
    if (typeof __TEMPLATE_DATA__ !== "undefined" && __TEMPLATE_DATA__ !== null) {
      template.data = __TEMPLATE_DATA__;
    }
    return template.evaluate().getContent();
  } catch (err) {
    console.error(`Include failed for ${name}:`, err);
    const msg = err && err.message ? err.message : String(err);
    return `<!-- include failed: ${name} :: ${msg} -->`;
  }
}

function runIncludePreflight() {
  const files = Array.from(ALLOWED_INCLUDES);

  const missing = files
    .map((file) => {
      const result = tryInclude_(file);
      return { file, ok: result === true, error: result === true ? null : result };
    })
    .filter((res) => !res.ok)
    .map((res) => ({ file: res.file, error: res.error }));

  return { ok: missing.length === 0, missing };
}

function tryInclude_(file) {
  try {
    HtmlService.createTemplateFromFile(file);
    return true;
  } catch (err) {
    return err && err.message ? err.message : String(err);
  }
}

function sanitizeParams_(e) {
  const raw = (e && e.parameter) ? e.parameter : {};
  const allowedKeys = ["view"];
  const cleaned = {};

  allowedKeys.forEach((key) => {
    if (Object.prototype.hasOwnProperty.call(raw, key)) {
      cleaned[key] = String(raw[key]);
    }
  });

  return cleaned;
}

function normalizeName_(s) {
  return String(s || "").trim().toLowerCase().replace(/\s+/g, " ");
}

function getRoleRates_() {
  const ss = getSS_();
  const sh = ss.getSheetByName(CFG.SHEETS.ROLLEN_SAETZE || "ROLLEN_SAETZE") || ss.getSheetByName("ROLLEN_SAETZE");
  if (!sh) return new Map();

  const rows = readTable_(sh);
  const m = new Map();
  rows.forEach((r) => {
    const role = String(r.rolle || r.role || "").trim();
    if (!role) return;
    const rate = Number(r.stundensatz_eur ?? r.stundensatz ?? 0) || 0;
    const billable = r.abrechenbar === undefined ? true : truthy_(r.abrechenbar);
    m.set(role, { role, rate, billable });
  });
  return m;
}

/** ====== API ====== */
function apiListActiveTrainers() {
  const ss = getSS_();
  const trainers = readTable_(ss.getSheetByName(CFG.SHEETS.TRAINER));
  return {
    ok: true,
    items: trainers
      .filter(t => truthy_(t.aktiv))
      .map(r => ({
        trainer_id: String(r.trainer_id || ""),
        name: String(r.name || ""),
        email: String(r.email || ""),
      }))
      .sort((a,b) => a.name.localeCompare(b.name, "de"))
  };
}

function apiListActiveTrainerNames() {
  const ss = getSS_();
  const trainers = readTable_(ss.getSheetByName(CFG.SHEETS.TRAINER));
  return {
    ok: true,
    items: trainers
      .filter(t => truthy_(t.aktiv))
      .map(r => ({
        name: String(r.name || ""),
      }))
      .sort((a,b) => a.name.localeCompare(b.name, "de")),
    names: trainers
      .filter(t => truthy_(t.aktiv))
      .map(r => String(r.name || ""))
      .sort((a,b) => a.localeCompare(b, "de")),
  };
}

function apiLogin(identifier, pin) {
  try {
    console.log("apiLogin", identifier ? "present" : "missing");
    const ss = getSS_();
    const sh = ss.getSheetByName(CFG.SHEETS.TRAINER);
    if (!sh) return { ok: false, error: `Sheet fehlt: ${CFG.SHEETS.TRAINER}` };

    const trainers = readTable_(sh);
    const { header, rowIndexByKey } = readTableWithMeta_(sh, "trainer_id");
    const search = String(identifier || "").trim();

    const getRowByIndex = (rowIndex) => {
      const idx = rowIndex - 2; // data rows start at sheet row 2
      return idx >= 0 && idx < trainers.length ? trainers[idx] : null;
    };

    const matchRowIndex = rowIndexByKey[search];
    const matchById = matchRowIndex ? getRowByIndex(matchRowIndex) : null;

    let matches = [];
    if (matchById) {
      matches = [matchById];
    } else {
      const normalizedSearch = normalizeName_(search);
      matches = trainers.filter((r) => normalizeName_(r.name) === normalizedSearch);
    }

    if (matches.length === 0) return { ok: false, error: "Trainer nicht gefunden." };
    if (matches.length > 1) {
      return {
        ok: false,
        error: "Name nicht eindeutig.",
        candidates: matches.map((m) => ({
          trainer_id: String(m.trainer_id || ""),
          name: String(m.name || ""),
        })),
      };
    }

    const t = matches[0];
    const rowIndex = rowIndexByKey[String(t.trainer_id || "").trim()];
    return performLogin_(t, pin, sh, header, rowIndex);
  } catch (e) {
    return { ok: false, error: (e && e.message) ? e.message : String(e) };
  }
}

function apiLoginByName(name, pin) {
  try {
    console.log("apiLoginByName", name);
    const ss = getSS_();
    const sh = ss.getSheetByName(CFG.SHEETS.TRAINER);
    if (!sh) return { ok: false, error: `Sheet fehlt: ${CFG.SHEETS.TRAINER}` };

    const trainers = readTable_(sh).filter((row) => truthy_(row.aktiv));
    const normalizedName = normalizeName_(name);
    const matches = trainers.filter((row) => normalizeName_(row.name) === normalizedName);

    if (matches.length === 0) return { ok: false, error: "Trainer nicht gefunden." };
    if (matches.length > 1) {
      return {
        ok: false,
        error: "Name nicht eindeutig.",
        candidates: matches.map((m) => ({
          trainer_id: String(m.trainer_id || ""),
          name: String(m.name || ""),
          email: String(m.email || ""),
        })),
      };
    }

    const trainer = matches[0];
    const { header, rowIndexByKey } = readTableWithMeta_(sh, "trainer_id");
    const rowIndex = rowIndexByKey[String(trainer.trainer_id || "").trim()];
    return performLogin_(trainer, pin, sh, header, rowIndex);
  } catch (e) {
    return { ok: false, error: (e && e.message) ? e.message : String(e) };
  }
}

function performLogin_(trainer, pin, sheet, header, rowIndex) {
  if (!trainer) return { ok: false, error: "Trainer nicht gefunden." };
  if (!truthy_(trainer.aktiv)) return { ok: false, error: "Trainer ist nicht aktiv." };

  const storedPin = String(trainer.pin || "").trim();
  const got = String(pin || "").trim();
  if (!verifyPin_(got, storedPin)) return { ok: false, error: "PIN falsch." };

  if (got && storedPin && !isHashedPin_(storedPin) && rowIndex) {
    try {
      setCell_(sheet, header, rowIndex, "pin", hashPin_(got));
    } catch (e) {
      // Silent migration – Fehler werden ignoriert
    }
  }

  if (rowIndex && header && header.indexOf("last_login") !== -1) {
    try {
      setCell_(sheet, header, rowIndex, "last_login", new Date());
    } catch (e) {
      // last_login setzen ist optional – Fehler ignorieren
    }
  }

  const token = createToken_();
  const roleRates = getRoleRates_();
  const rolle_standard = String(trainer.rolle_standard || "Trainer");
  const roleRate = roleRates.get(rolle_standard);
  const effectiveRate = roleRate ? roleRate.rate : Number(trainer.stundensatz_eur ?? trainer.stundensatz ?? 0);

  saveSession_(token, {
    trainer_id: String(trainer.trainer_id),
    email: String(trainer.email || ""),
    name: String(trainer.name || ""),
    is_admin: truthy_(trainer.is_admin),
    rolle_standard,
    stundensatz: Number(effectiveRate || 0),
    notizen: String(trainer.notizen || ""),
    last_login: formatDateTime_(new Date()),
  });

  return { ok: true, token, user: getSession_(token) };
}

function ADMIN_testLoginByName() {
  Logger.log(apiLoginByName("Sascha Bazynski", "1234"));
}

function apiLogout(token) {
  clearSession_(token);
  return { ok: true };
}

function apiBootstrap(token) {
  try {
    if (!token || !String(token).trim()) {
      console.log("apiBootstrap token missing");
      return { ok: false, error: "Session abgelaufen." };
    }
    console.log("apiBootstrap token", token ? "present" : "missing");
    const user = requireSession_(token);
    const ss = getSS_();

    const shT = ss.getSheetByName(CFG.SHEETS.TRAININGS);
    const shE = ss.getSheetByName(CFG.SHEETS.EINTEILUNGEN);
    if (!shT) return { ok:false, error:`Sheet fehlt: ${CFG.SHEETS.TRAININGS}` };
    if (!shE) return { ok:false, error:`Sheet fehlt: ${CFG.SHEETS.EINTEILUNGEN}` };

    const trainings = readTable_(shT, { displayCols: ["start", "ende"] });
    const einteilungen = readTable_(shE);

    const abmeldungen = readTableSafe_(ss.getSheetByName(CFG.SHEETS.ABMELDUNGEN));
    const myAbm = abmeldungen.filter(a =>
      String(a.trainer_id) === String(user.trainer_id) &&
      isBlank_(a.deleted_at)
    );
    const unavailSet = new Set(myAbm.map(a => String(a.training_id)));

    // Upcoming planned trainings (incl. today)
    const today = startOfDay_(new Date());
    const upcoming = trainings
      .filter(tr => String(tr.status) === "geplant" && toDate_(tr.datum) && startOfDay_(toDate_(tr.datum)).getTime() >= today.getTime())
      .map(tr => {
        const t = enrichTraining_(tr, einteilungen);
        t.is_unavailable = unavailSet.has(String(t.training_id));
        return t;
      })
      .sort((a,b) => a.datumTs - b.datumTs);

    const mineActive = einteilungen
      .filter(e => String(e.trainer_id) === user.trainer_id && isBlank_(e.ausgetragen_am))
      .map(e => enrichEinteilung_(e, trainings))
      .sort((a,b) => a.trainingDatumTs - b.trainingDatumTs);

    const myUnavailable = myAbm.map(a => {
      const tr = trainings.find(t => String(t.training_id) === String(a.training_id));
      if (!tr) return { training_id: String(a.training_id), label: String(a.training_id) };
      const d = toDate_(tr.datum);
      return {
        training_id: String(a.training_id),
        label: `${formatDate_(d)} · ${fmtTime_(tr.start)}–${fmtTime_(tr.ende)} · ${String(tr.gruppe||"")}`
      };
    });

    const allTrainings = trainings
      .filter(tr => toDate_(tr.datum))
      .map(tr => {
        const t = enrichTraining_(tr, einteilungen);
        t.is_unavailable = unavailSet.has(String(t.training_id));
        return t;
      })
      .sort((a,b) => a.datumTs - b.datumTs);

    const shTu = ss.getSheetByName(CFG.SHEETS.TURNIERE);
    const shTe = ss.getSheetByName(CFG.SHEETS.TURNIER_EINSAETZE);
    const shF = ss.getSheetByName(CFG.SHEETS.FAHRTEN);
    if (!shTu) return { ok:false, error:`Sheet fehlt: ${CFG.SHEETS.TURNIERE}` };
    if (!shTe) return { ok:false, error:`Sheet fehlt: ${CFG.SHEETS.TURNIER_EINSAETZE}` };
    if (!shF) return { ok:false, error:`Sheet fehlt: ${CFG.SHEETS.FAHRTEN}` };

    const turniereRaw = readTable_(shTu);
    const einsaetze = readTable_(shTe);
    const fahrten = readTableSafe_(shF);

    const todayTs = startOfDay_(new Date()).getTime();
    const turniere = turniereRaw
      .filter((t) => isBlank_(t.deleted_at))
      .map(mapTurnierRow_);

    const turniereUpcoming = turniere
      .filter((t) => t.datumVonTs >= todayTs)
      .sort((a, b) => a.datumVonTs - b.datumVonTs);

    const turnierePast = turniere
      .filter((t) => t.datumVonTs < todayTs)
      .sort((a, b) => b.datumVonTs - a.datumVonTs);

    const myTurniere = einsaetze
      .filter((e) => String(e.trainer_id) === String(user.trainer_id))
      .map((e) => ({
        turnier_einsatz_id: String(e.turnier_einsatz_id || ""),
        turnier_id: String(e.turnier_id || ""),
        datum: e.datum,
        rolle: String(e.rolle || ""),
        anwesend: String(e.anwesend || ""),
        pauschale_tag_eur: e.pauschale_tag_eur,
        freigegeben: e.freigegeben,
        kommentar: String(e.kommentar || ""),
      }));

    const myFahrten = fahrten
      .filter((f) => String(f.fahrer_trainer_id || f.fahrer_id || "") === String(user.trainer_id))
      .map((f) => ({
        fahrt_id: String(f.fahrt_id || ""),
        turnier_id: String(f.turnier_id || ""),
        datum: f.datum,
        km_gesamt: f.km_gesamt,
        km_satz_eur: f.km_satz_eur,
        km_betrag_eur: f.km_betrag_eur,
        freigegeben: f.freigegeben,
        kommentar: String(f.kommentar || ""),
      }));

    return {
      ok: true,
      user,
      upcoming,
      allTrainings,
      mineActive,
      myUnavailable,
      turniere_upcoming: turniereUpcoming,
      turniere_past: turnierePast,
      my_turniere: myTurniere,
      my_fahrten: myFahrten,
    };
  } catch (e) {
    return { ok:false, error: (e && e.message) ? e.message : String(e) };
  }
}

function apiGetMyProfile(token) {
  const user = requireSession_(token);
  const ss = getSS_();
  const trainers = readTable_(ss.getSheetByName(CFG.SHEETS.TRAINER));
  const t = trainers.find(r => String(r.trainer_id) === String(user.trainer_id));
  if (!t) return { ok:false, error:"Trainer nicht gefunden." };
  return {
    ok: true,
    trainer: {
      trainer_id: String(t.trainer_id),
      name: String(t.name || ""),
      email: String(t.email || ""),
      rolle_standard: String(t.rolle_standard || "Trainer"),
      stundensatz: Number(t.stundensatz_eur ?? t.stundensatz ?? 0),
      is_admin: truthy_(t.is_admin),
      notizen: String(t.notizen || ""),
    }
  };
}

function apiUpdateMyProfile(token, payload) {
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    const user = requireSession_(token);
    const ss = getSS_();
    const sh = ss.getSheetByName(CFG.SHEETS.TRAINER);
    if (!sh) return { ok:false, error:`Sheet fehlt: ${CFG.SHEETS.TRAINER}` };

    const { header, rowIndexByKey } = readTableWithMeta_(sh, "trainer_id");
    const idx = rowIndexByKey[String(user.trainer_id)];
    if (!idx) return { ok:false, error:"Trainer nicht gefunden." };

    if (payload && Object.prototype.hasOwnProperty.call(payload, "email")) {
      setCell_(sh, header, idx, "email", String(payload.email || ""));
    }
    if (payload && Object.prototype.hasOwnProperty.call(payload, "notizen")) {
      setCell_(sh, header, idx, "notizen", String(payload.notizen || ""));
    }

    const session = getSession_(token);
    if (session) {
      saveSession_(token, { ...session, email: String(payload.email || session.email || ""), notizen: String(payload.notizen || "") });
    }

    return { ok:true };
  } finally {
    lock.releaseLock();
  }
}

function apiChangeMyPin(token, oldPin, newPin) {
  const user = requireSession_(token);
  const ss = getSS_();
  const sh = ss.getSheetByName(CFG.SHEETS.TRAINER);
  const { header, rowIndexByKey } = readTableWithMeta_(sh, "trainer_id");
  const idx = rowIndexByKey[String(user.trainer_id)];
  if (!idx) return { ok:false, error:"Trainer nicht gefunden." };

  const cur = String(getCell_(sh, header, idx, "pin") || "").trim();
  if (!verifyPin_(oldPin, cur)) return { ok:false, error:"Aktuelle PIN ist falsch." };

  const np = String(newPin || "").trim();
  if (!/^\d{4,8}$/.test(np)) return { ok:false, error:"Neue PIN muss 4–8 Ziffern haben." };

  setCell_(sh, header, idx, "pin", hashPin_(np));
  return { ok:true };
}

function apiTrainingDetails(token, trainingId) {
  requireSession_(token);
  const ss = getSS_();

  const shT = ss.getSheetByName(CFG.SHEETS.TRAININGS);
  const shE = ss.getSheetByName(CFG.SHEETS.EINTEILUNGEN);
  const shR = ss.getSheetByName(CFG.SHEETS.TRAINER);

  const trainings = readTable_(shT, { displayCols: ["start", "ende"] });
  const einteilungen = readTable_(shE);
  const trainers = readTable_(shR);

  const tr = trainings.find(t => String(t.training_id) === String(trainingId));
  if (!tr) return { ok:false, error:"Training nicht gefunden." };

  const enriched = enrichTraining_(tr, einteilungen);

  // Eingetragene Trainer (aktive Einteilungen)
  const signups = einteilungen
    .filter(e => String(e.training_id) === String(trainingId) && isBlank_(e.ausgetragen_am))
    .map(e => {
      const tt = trainers.find(x => String(x.trainer_id) === String(e.trainer_id)) || {};
      return {
        trainer_id: String(e.trainer_id),
        name: String(tt.name || e.trainer_id),
        rolle: String(e.rolle || tt.rolle_standard || "Trainer"),
        checkin_am: e.checkin_am ? formatDateTime_(toDate_(e.checkin_am)) : "",
        attendance: String(e.attendance || ""),
      };
    })
    .sort((a,b)=> a.name.localeCompare(b.name, "de"));

  // Nicht verfügbar (ABMELDUNGEN) – aktiv, wenn deleted_at leer
  const abm = readTableSafe_(ss.getSheetByName(CFG.SHEETS.ABMELDUNGEN));
  const unavailable = abm
    .filter(a =>
      String(a.training_id) === String(trainingId) &&
      isBlank_(a.deleted_at)
    )
    .map(a => {
      const tt = trainers.find(x => String(x.trainer_id) === String(a.trainer_id)) || {};
      return {
        trainer_id: String(a.trainer_id || ""),
        name: String(tt.name || a.trainer_id || ""),
        grund: String(a.grund || ""),
        created_at: a.created_at ? formatDateTime_(toDate_(a.created_at)) : "",
      };
    })
    .sort((a,b)=> a.name.localeCompare(b.name, "de"));

  let plan = null;
  try {
    const shP = ss.getSheetByName(CFG.SHEETS.TRAININGSPLAN);
    if (shP) {
      const plans = readTable_(shP).filter(p =>
        String(p.training_id) === String(trainingId) &&
        isBlank_(p.deleted_at)
      );

      if (plans.length) {
        plans.sort((a, b) => {
          const bDate = toDate_(b.updated_at) || toDate_(b.created_at) || new Date(0);
          const aDate = toDate_(a.updated_at) || toDate_(a.created_at) || new Date(0);
          return bDate.getTime() - aDate.getTime();
        });
        const p = plans[0];
        plan = {
          plan_id: String(p.plan_id || ""),
          training_id: String(p.training_id || ""),
          titel: String(p.titel || ""),
          inhalt: String(p.inhalt || ""),
          link: String(p.link || ""),
          created_at: dtStr_(p.created_at),
          created_by: String(p.created_by || ""),
          updated_at: dtStr_(p.updated_at),
          updated_by: String(p.updated_by || ""),
        };
      }
    }
  } catch (e) {
    plan = null;
  }

  return { ok:true, training: enriched, signups, unavailable, plan };
}


function apiEnroll(token, trainingId) {
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    const user = requireSession_(token);
    const ss = getSS_();

    const shT = ss.getSheetByName(CFG.SHEETS.TRAININGS);
    const shE = ss.getSheetByName(CFG.SHEETS.EINTEILUNGEN);

    const trainings = readTable_(shT, { displayCols: ["start", "ende"] });
    const einteilungen = readTable_(shE);

    const tr = trainings.find(x => String(x.training_id) === String(trainingId));
    if (!tr) return { ok: false, error: "Training nicht gefunden." };

    if (String(tr.status) !== "geplant") return { ok: false, error: "Training ist nicht geplant." };

    // block if trainer set unavailable
    const shA = ss.getSheetByName(CFG.SHEETS.ABMELDUNGEN);
    if (shA) {
      const abm = readTableSafe_(shA);
      const blocked = abm.some(a =>
        String(a.training_id) === String(trainingId) &&
        String(a.trainer_id) === String(user.trainer_id) &&
        isBlank_(a.deleted_at)
      );
      if (blocked) return { ok:false, error:"Du hast dich für dieses Training als nicht verfügbar gemeldet." };
    }

    // already assigned?
    const already = einteilungen.find(e =>
      String(e.training_id) === String(trainingId) &&
      String(e.trainer_id) === String(user.trainer_id) &&
      isBlank_(e.ausgetragen_am)
    );
    if (already) return { ok: true };

    appendRow_(shE, {
      einteilung_id: "E_" + createShortId_(),
      training_id: String(trainingId),
      trainer_id: String(user.trainer_id),
      rolle: String(user.rolle_standard || "Trainer"),
      attendance: "OFFEN",
      checkin_am: "",
      checkin_nachgetragen: "",
      ausgetragen_am: "",
      created_at: new Date(),
    });

    return { ok: true };
  } finally {
    lock.releaseLock();
  }
}

function apiWithdraw(token, einteilungId) {
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    const user = requireSession_(token);
    const ss = getSS_();
    const shE = ss.getSheetByName(CFG.SHEETS.EINTEILUNGEN);
    const { header, rowIndexByKey } = readTableWithMeta_(shE, "einteilung_id");

    const idx = rowIndexByKey[String(einteilungId)];
    if (!idx) return { ok: false, error: "Einteilung nicht gefunden." };

    // check ownership or admin
    const trainerId = String(shE.getRange(idx, header.indexOf("trainer_id") + 1).getValue() || "");
    if (trainerId !== String(user.trainer_id) && !user.is_admin) {
      return { ok: false, error: "Nicht berechtigt." };
    }

    setCell_(shE, header, idx, "ausgetragen_am", new Date());
    return { ok: true };
  } finally {
    lock.releaseLock();
  }
}

function apiCheckin(token, einteilungId, mode) {
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    const user = requireSession_(token);
    const ss = getSS_();
    const shE = ss.getSheetByName(CFG.SHEETS.EINTEILUNGEN);
    const { header, rowIndexByKey } = readTableWithMeta_(shE, "einteilung_id");

    const idx = rowIndexByKey[String(einteilungId)];
    if (!idx) return { ok:false, error:"Einteilung nicht gefunden." };

    // ownership or admin
    const trainerId = String(shE.getRange(idx, header.indexOf("trainer_id") + 1).getValue() || "");
    if (trainerId !== String(user.trainer_id) && !user.is_admin) {
      return { ok:false, error:"Nicht berechtigt." };
    }

    const now = new Date();
    setCell_(shE, header, idx, "checkin_am", now);
    setCell_(shE, header, idx, "attendance", "JA");
    return { ok:true };
  } finally {
    lock.releaseLock();
  }
}

function apiAdminSetTrainingStatus(token, trainingId, status, reason) {
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    const user = requireSession_(token);
    if (!user.is_admin) return { ok: false, error: "Nur Admin." };

    const ss = getSS_();
    const shT = ss.getSheetByName(CFG.SHEETS.TRAININGS);
    const { header, rowIndexByKey } = readTableWithMeta_(shT, "training_id");

    const idx = rowIndexByKey[String(trainingId)];
    if (!idx) return { ok: false, error: "Training nicht gefunden." };

    const s = String(status || "");
    if (!["geplant", "stattgefunden", "ausgefallen"].includes(s)) {
      return { ok: false, error: "Ungültiger Status." };
    }

    setCell_(shT, header, idx, "status", s);
    if (s === "ausgefallen") {
      setCellIfNoFormula_(shT, header, idx, "ausfall_grund", String(reason || "Admin"));
    } else {
      setCellIfNoFormula_(shT, header, idx, "ausfall_grund", "");
    }
    return { ok: true };
  } finally {
    lock.releaseLock();
  }
}

/** ====== Turniere ====== */
function apiTurniereList(token, opts) {
  try {
    requireSession_(token);
    const { turniere } = loadTurnierData_();
    const includePast = opts && truthy_(opts.includePast);
    const todayTs = startOfDay_(new Date()).getTime();
    const items = turniere
      .filter((t) => includePast || t.datumVonTs >= todayTs)
      .sort((a, b) => a.datumVonTs - b.datumVonTs);
    return { ok: true, items };
  } catch (e) {
    return { ok: false, error: e && e.message ? e.message : String(e) };
  }
}

function apiTurnierDetails(token, turnierId) {
  try {
    const user = requireSession_(token);
    const { ss, turniere, einsaetze, trainers, fahrten } = loadTurnierData_(true);
    const turnier = turniere.find((t) => String(t.turnier_id) === String(turnierId));
    if (!turnier) return { ok: false, error: "Turnier nicht gefunden." };

    const signups = einsaetze
      .filter((e) =>
        String(e.turnier_id) === String(turnierId) &&
        isActiveTurnierSignup_(e.anwesend)
      )
      .map((e) => {
        const tr = trainers.find((t) => String(t.trainer_id) === String(e.trainer_id)) || {};
        return {
          trainer_id: String(e.trainer_id || ""),
          name: String(tr.name || e.trainer_id || ""),
          rolle: String(e.rolle || tr.rolle_standard || ""),
          anwesend: String(e.anwesend || ""),
          kommentar: String(e.kommentar || ""),
        };
      })
      .sort((a, b) => a.name.localeCompare(b.name, "de"));

    const unavailable = einsaetze
      .filter((e) =>
        String(e.turnier_id) === String(turnierId) &&
        isUnavailableTurnier_(e.anwesend)
      )
      .map((e) => {
        const tr = trainers.find((t) => String(t.trainer_id) === String(e.trainer_id)) || {};
        return {
          trainer_id: String(e.trainer_id || ""),
          name: String(tr.name || e.trainer_id || ""),
          kommentar: String(e.kommentar || ""),
        };
      })
      .sort((a, b) => a.name.localeCompare(b.name, "de"));

    const myStatus = einsaetze.find((e) =>
      String(e.turnier_id) === String(turnierId) &&
      String(e.trainer_id) === String(user.trainer_id)
    );

    const myFahrt = fahrten.find((f) =>
      String(f.turnier_id) === String(turnierId) &&
      String(f.fahrer_trainer_id || f.fahrer_id || "") === String(user.trainer_id)
    );

    return { ok: true, turnier, signups, unavailable, myStatus, myFahrt };
  } catch (e) {
    return { ok: false, error: e && e.message ? e.message : String(e) };
  }
}

function apiTurnierEnroll(token, turnierId) {
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    const user = requireSession_(token);
    const { shTe, headerTe, turniere, einsaetze } = loadTurnierData_();

    const turnier = turniere.find((t) => String(t.turnier_id) === String(turnierId));
    if (!turnier) return { ok: false, error: "Turnier nicht gefunden." };

    const blocked = einsaetze.find((e) =>
      String(e.turnier_id) === String(turnierId) &&
      String(e.trainer_id) === String(user.trainer_id) &&
      isUnavailableTurnier_(e.anwesend)
    );
    if (blocked) return { ok: false, error: "Du bist als nicht verfügbar markiert." };

    const active = einsaetze.find((e) =>
      String(e.turnier_id) === String(turnierId) &&
      String(e.trainer_id) === String(user.trainer_id) &&
      isActiveTurnierSignup_(e.anwesend)
    );
    if (active) return { ok: true };

    const existing = einsaetze.find((e) =>
      String(e.turnier_id) === String(turnierId) &&
      String(e.trainer_id) === String(user.trainer_id)
    );

    const dv = turnier.datum_von_raw || toDate_(turnier.datum_von) || new Date();
    const payload = {
      turnier_einsatz_id: existing && existing.turnier_einsatz_id ? String(existing.turnier_einsatz_id) : "TE_" + createShortId_(),
      turnier_id: String(turnierId),
      trainer_id: String(user.trainer_id),
      datum: dv instanceof Date ? dv : toDate_(dv),
      rolle: String(user.rolle_standard || ""),
      anwesend: "OFFEN",
      pauschale_tag_eur: turnier.pauschale_tag_eur,
      freigegeben: "",
    };

    if (existing && existing.turnier_einsatz_id) {
      const { rowIndexByKey } = readTableWithMeta_(shTe, "turnier_einsatz_id");
      const idx = rowIndexByKey[String(existing.turnier_einsatz_id)];
      if (!idx) return { ok: false, error: "Einsatz-Zeile nicht gefunden." };
      Object.keys(payload).forEach((key) => safeSetCell_(shTe, headerTe, idx, key, payload[key]));
    } else {
      appendRow_(shTe, payload);
    }

    return { ok: true };
  } catch (e) {
    return { ok: false, error: e && e.message ? e.message : String(e) };
  } finally {
    lock.releaseLock();
  }
}

function apiTurnierWithdraw(token, turnierId) {
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    const user = requireSession_(token);
    const { shTe, headerTe, einsaetze } = loadTurnierData_();

    const active = einsaetze.find((e) =>
      String(e.turnier_id) === String(turnierId) &&
      String(e.trainer_id) === String(user.trainer_id) &&
      isActiveTurnierSignup_(e.anwesend)
    );
    if (!active || !active.turnier_einsatz_id) {
      return { ok: false, error: "Keine aktive Eintragung gefunden." };
    }

    const { rowIndexByKey } = readTableWithMeta_(shTe, "turnier_einsatz_id");
    const idx = rowIndexByKey[String(active.turnier_einsatz_id)];
    if (!idx) return { ok: false, error: "Einsatz-Zeile nicht gefunden." };

    setCell_(shTe, headerTe, idx, "anwesend", "AUSGETRAGEN");
    return { ok: true };
  } catch (e) {
    return { ok: false, error: e && e.message ? e.message : String(e) };
  } finally {
    lock.releaseLock();
  }
}

function apiTurnierSetUnavailable(token, turnierId, grund) {
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    const user = requireSession_(token);
    const { shTe, headerTe, turniere, einsaetze } = loadTurnierData_();

    const turnier = turniere.find((t) => String(t.turnier_id) === String(turnierId));
    if (!turnier) return { ok: false, error: "Turnier nicht gefunden." };

    const active = einsaetze.find((e) =>
      String(e.turnier_id) === String(turnierId) &&
      String(e.trainer_id) === String(user.trainer_id) &&
      isActiveTurnierSignup_(e.anwesend)
    );
    if (active) return { ok: false, error: "Du bist bereits eingetragen. Bitte erst austragen." };

    const existing = einsaetze.find((e) =>
      String(e.turnier_id) === String(turnierId) &&
      String(e.trainer_id) === String(user.trainer_id)
    );

    const dv = turnier.datum_von_raw || toDate_(turnier.datum_von) || new Date();
    const payload = {
      turnier_einsatz_id: existing && existing.turnier_einsatz_id ? String(existing.turnier_einsatz_id) : "TE_" + createShortId_(),
      turnier_id: String(turnierId),
      trainer_id: String(user.trainer_id),
      datum: dv instanceof Date ? dv : toDate_(dv),
      rolle: String(user.rolle_standard || ""),
      anwesend: "NICHT_VERFUEGBAR",
      kommentar: String(grund || ""),
      pauschale_tag_eur: turnier.pauschale_tag_eur,
      freigegeben: "",
    };

    if (existing && existing.turnier_einsatz_id) {
      const { rowIndexByKey } = readTableWithMeta_(shTe, "turnier_einsatz_id");
      const idx = rowIndexByKey[String(existing.turnier_einsatz_id)];
      if (!idx) return { ok: false, error: "Einsatz-Zeile nicht gefunden." };
      Object.keys(payload).forEach((key) => safeSetCell_(shTe, headerTe, idx, key, payload[key]));
    } else {
      appendRow_(shTe, payload);
    }

    return { ok: true };
  } catch (e) {
    return { ok: false, error: e && e.message ? e.message : String(e) };
  } finally {
    lock.releaseLock();
  }
}

function apiTurnierUnsetUnavailable(token, turnierId) {
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    const user = requireSession_(token);
    const { shTe, headerTe, einsaetze } = loadTurnierData_();

    const existing = einsaetze.find((e) =>
      String(e.turnier_id) === String(turnierId) &&
      String(e.trainer_id) === String(user.trainer_id) &&
      isUnavailableTurnier_(e.anwesend)
    );
    if (!existing || !existing.turnier_einsatz_id) {
      return { ok: false, error: "Keine Nicht-Verfügbar-Meldung gefunden." };
    }

    const { rowIndexByKey } = readTableWithMeta_(shTe, "turnier_einsatz_id");
    const idx = rowIndexByKey[String(existing.turnier_einsatz_id)];
    if (!idx) return { ok: false, error: "Einsatz-Zeile nicht gefunden." };

    setCell_(shTe, headerTe, idx, "anwesend", "AUSGETRAGEN");
    return { ok: true };
  } catch (e) {
    return { ok: false, error: e && e.message ? e.message : String(e) };
  } finally {
    lock.releaseLock();
  }
}

function apiTurnierCheckin(token, turnierId, kmGesamt) {
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    const user = requireSession_(token);
    const { ss, shTe, headerTe, shF, headerF, turniere, einsaetze } = loadTurnierData_(true);

    const turnier = turniere.find((t) => String(t.turnier_id) === String(turnierId));
    if (!turnier) return { ok: false, error: "Turnier nicht gefunden." };

    const active = einsaetze.find((e) =>
      String(e.turnier_id) === String(turnierId) &&
      String(e.trainer_id) === String(user.trainer_id) &&
      isActiveTurnierSignup_(e.anwesend)
    );
    if (!active || !active.turnier_einsatz_id) {
      return { ok: false, error: "Keine aktive Eintragung gefunden." };
    }

    const { rowIndexByKey } = readTableWithMeta_(shTe, "turnier_einsatz_id");
    const idx = rowIndexByKey[String(active.turnier_einsatz_id)];
    if (!idx) return { ok: false, error: "Einsatz-Zeile nicht gefunden." };

    setCell_(shTe, headerTe, idx, "anwesend", "JA");

    const km = Number(kmGesamt || 0);
    if (km > 0) {
      const dv = turnier.datum_von_raw || toDate_(turnier.datum_von) || new Date();
      const fahrtenRows = readTable_(shF);
      const existing = fahrtenRows.find((f) =>
        String(f.turnier_id) === String(turnierId) &&
        String(f.fahrer_trainer_id || f.fahrer_id || "") === String(user.trainer_id)
      );

      const payload = {
        fahrt_id: existing && existing.fahrt_id ? String(existing.fahrt_id) : "F_" + createShortId_(),
        turnier_id: String(turnierId),
        datum: dv instanceof Date ? dv : toDate_(dv),
        fahrer_trainer_id: String(user.trainer_id),
        km_gesamt: km,
        km_satz_eur: turnier.km_satz_eur,
        freigegeben: "",
        kommentar: existing ? existing.kommentar : "",
      };

      if (existing && existing.fahrt_id) {
        const meta = readTableWithMeta_(shF, "fahrt_id");
        const fIdx = meta.rowIndexByKey[String(existing.fahrt_id)];
        if (!fIdx) return { ok: false, error: "Fahrt nicht gefunden." };
        Object.keys(payload).forEach((key) => safeSetCell_(shF, headerF, fIdx, key, payload[key]));
      } else {
        appendRow_(shF, payload);
      }
    }

    return { ok: true };
  } catch (e) {
    return { ok: false, error: e && e.message ? e.message : String(e) };
  } finally {
    lock.releaseLock();
  }
}

function apiAdminListTurniere(token) {
  try {
    requireAdmin_(token);
    const { turniere } = loadTurnierData_(true, true);
    return { ok: true, items: turniere };
  } catch (e) {
    return { ok: false, error: e && e.message ? e.message : String(e) };
  }
}

function apiAdminUpsertTurnier(token, payload) {
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    const admin = requireAdmin_(token);
    const { shTu, headerTu } = loadTurnierData_(true);

    const turnier_id = String(payload && payload.turnier_id ? payload.turnier_id : "").trim() || "TU_" + createShortId_();
    const { rowIndexByKey } = readTableWithMeta_(shTu, "turnier_id");
    const idx = rowIndexByKey[String(turnier_id)];

    const obj = {
      turnier_id,
      name: payload ? payload.name : "",
      datum_von: payload && payload.datum_von ? toDate_(payload.datum_von) || payload.datum_von : "",
      datum_bis: payload && payload.datum_bis ? toDate_(payload.datum_bis) || payload.datum_bis : "",
      ort: payload ? payload.ort : "",
      pauschale_tag_eur: payload ? payload.pauschale_tag_eur : "",
      km_satz_eur: payload ? payload.km_satz_eur : "",
      bemerkung: payload ? payload.bemerkung : "",
    };

    if (idx) {
      Object.keys(obj).forEach((key) => safeSetCell_(shTu, headerTu, idx, key, obj[key]));
      safeSetCell_(shTu, headerTu, idx, "updated_at", new Date());
      safeSetCell_(shTu, headerTu, idx, "updated_by", admin && admin.name ? admin.name : admin.trainer_id);
    } else {
      appendRow_(shTu, obj);
    }

    return { ok: true, turnier_id };
  } catch (e) {
    return { ok: false, error: e && e.message ? e.message : String(e) };
  } finally {
    lock.releaseLock();
  }
}

function apiAdminDeleteTurnier(token, turnierId) {
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    requireAdmin_(token);
    const { shTu } = loadTurnierData_(true);
    ensureColumns_(shTu, ["deleted_at"]);
    const { header, rowIndexByKey } = readTableWithMeta_(shTu, "turnier_id");
    const idx = rowIndexByKey[String(turnierId)];
    if (!idx) return { ok: false, error: "Turnier nicht gefunden." };

    setCell_(shTu, header, idx, "deleted_at", new Date());
    return { ok: true };
  } catch (e) {
    return { ok: false, error: e && e.message ? e.message : String(e) };
  } finally {
    lock.releaseLock();
  }
}

function loadTurnierData_(includeAll = false, includeDeleted = false) {
  const ss = getSS_();
  const shTu = ss.getSheetByName(CFG.SHEETS.TURNIERE);
  const shTe = ss.getSheetByName(CFG.SHEETS.TURNIER_EINSAETZE);
  const shF = ss.getSheetByName(CFG.SHEETS.FAHRTEN);
  const shR = ss.getSheetByName(CFG.SHEETS.TRAINER);
  if (!shTu) throw new Error(`Sheet fehlt: ${CFG.SHEETS.TURNIERE}`);
  if (!shTe) throw new Error(`Sheet fehlt: ${CFG.SHEETS.TURNIER_EINSAETZE}`);
  if (!shF) throw new Error(`Sheet fehlt: ${CFG.SHEETS.FAHRTEN}`);
  if (!shR) throw new Error(`Sheet fehlt: ${CFG.SHEETS.TRAINER}`);

  const turniereRaw = readTable_(shTu);
  const einsaetze = readTable_(shTe);
  const trainers = includeAll ? readTable_(shR) : [];
  const fahrten = includeAll ? readTable_(shF) : [];

  const turniere = turniereRaw
    .filter((t) => includeDeleted || isBlank_(t.deleted_at))
    .map(mapTurnierRow_);

  const headerTe = shTe.getRange(1, 1, 1, shTe.getLastColumn()).getValues()[0].map((h) => String(h).trim());
  const headerTu = shTu.getRange(1, 1, 1, shTu.getLastColumn()).getValues()[0].map((h) => String(h).trim());
  const headerF = shF.getRange(1, 1, 1, shF.getLastColumn()).getValues()[0].map((h) => String(h).trim());

  return { ss, shTu, shTe, shF, headerTu, headerTe, headerF, turniere, einsaetze, trainers, fahrten };
}

function mapTurnierRow_(t) {
  const dv = toDate_(t.datum_von);
  const db = toDate_(t.datum_bis);
  const datumVonTs = dv ? startOfDay_(dv).getTime() : 0;
  const datumBisTs = db ? startOfDay_(db).getTime() : 0;
  return {
    turnier_id: String(t.turnier_id || ""),
    name: String(t.name || ""),
    ort: String(t.ort || ""),
    datum_von: dv ? formatDate_(dv) : String(t.datum_von || ""),
    datum_bis: db ? formatDate_(db) : String(t.datum_bis || ""),
    datumVonTs,
    datumBisTs,
    datum_von_raw: dv || t.datum_von || "",
    datum_bis_raw: db || t.datum_bis || "",
    pauschale_tag_eur: Number(t.pauschale_tag_eur || 0) || 0,
    km_satz_eur: Number(t.km_satz_eur || 0) || 0,
    bemerkung: String(t.bemerkung || ""),
    deleted_at: t.deleted_at,
  };
}

function isActiveTurnierSignup_(status) {
  const val = String(status || "").toUpperCase();
  return val === "OFFEN" || val === "JA";
}

function isUnavailableTurnier_(status) {
  const val = String(status || "").toUpperCase();
  return val === "NICHT_VERFUEGBAR";
}

// Stellt sicher, dass betrag_eur wieder als Formel vorliegt (z.B. falls zuvor überschrieben).
function ADMIN_restoreEinteilungenBillingFormulas() {
  const ss = getSS_();
  const sh = ss.getSheetByName(CFG.SHEETS.EINTEILUNGEN);
  if (!sh) return { ok: false, error: `Sheet fehlt: ${CFG.SHEETS.EINTEILUNGEN}` };

  const values = sh.getDataRange().getValues();
  if (!values.length) return { ok: false, error: "Sheet hat keine Daten." };

  const header = values[0].map((h) => String(h).trim());
  const idx = (name) => header.indexOf(name);

  const iTrainingStatus = idx("training_status");
  const iAttendance = idx("attendance");
  const iSatz = idx("satz_eur");
  const iBetrag = idx("betrag_eur");

  if (iTrainingStatus === -1 || iAttendance === -1 || iSatz === -1 || iBetrag === -1) {
    return { ok: false, error: "Benötigte Spalten fehlen (training_status, attendance, satz_eur, betrag_eur)." };
  }

  const lastRow = sh.getLastRow();
  if (lastRow < 2) return { ok: true, restoredBetrag: 0, restoredSatz: 0 };

  let satzTemplateRange = null;
  for (let r = 2; r <= lastRow; r++) {
    const f = sh.getRange(r, iSatz + 1).getFormula();
    if (f && String(f).trim() !== "") {
      satzTemplateRange = sh.getRange(r, iSatz + 1);
      break;
    }
  }

  let restoredBetrag = 0;
  let restoredSatz = 0;

  for (let r = 2; r <= lastRow; r++) {
    const betragCell = sh.getRange(r, iBetrag + 1);
    const betragFormula = String(betragCell.getFormula() || "").trim();
    if (!betragFormula) {
      const trainingStatusRef = sh.getRange(r, iTrainingStatus + 1).getA1Notation();
      const attendanceRef = sh.getRange(r, iAttendance + 1).getA1Notation();
      const satzRef = sh.getRange(r, iSatz + 1).getA1Notation();
      const formula = `=IF(OR(${trainingStatusRef}<>"stattgefunden", ${attendanceRef}<>"JA"), 0, ${satzRef})`;
      betragCell.setFormula(formula);
      restoredBetrag++;
    }

    if (satzTemplateRange) {
      const satzCell = sh.getRange(r, iSatz + 1);
      const satzFormula = String(satzCell.getFormula() || "").trim();
      if (!satzFormula) {
        satzTemplateRange.copyTo(satzCell, { contentsOnly: false });
        restoredSatz++;
      }
    }
  }

  return { ok: true, restoredBetrag, restoredSatz };
}

function apiAdminUpsertTrainingPlan(token, trainingId, payload) {
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    const user = requireSession_(token);
    if (!user.is_admin) return { ok: false, error: "Nur Admin." };

    const ss = getSS_();
    const shT = ss.getSheetByName(CFG.SHEETS.TRAININGS);
    const shP = ss.getSheetByName(CFG.SHEETS.TRAININGSPLAN);
    if (!shT) return { ok: false, error: `Sheet fehlt: ${CFG.SHEETS.TRAININGS}` };
    if (!shP) return { ok: false, error: `Sheet fehlt: ${CFG.SHEETS.TRAININGSPLAN}` };

    const trainings = readTable_(shT, { displayCols: ["start", "ende"] });
    const tr = trainings.find(t => String(t.training_id) === String(trainingId));
    if (!tr) return { ok: false, error: "Training nicht gefunden." };

    const { header, rowIndexByKey } = readTableWithMeta_(shP, "plan_id");
    const plans = readTable_(shP).filter(p =>
      String(p.training_id) === String(trainingId) &&
      isBlank_(p.deleted_at)
    );

    plans.sort((a, b) => {
      const bDate = toDate_(b.updated_at) || toDate_(b.created_at) || new Date(0);
      const aDate = toDate_(a.updated_at) || toDate_(a.created_at) || new Date(0);
      return bDate.getTime() - aDate.getTime();
    });

    const existing = plans[0];
    const now = new Date();
    const title = String(payload && payload.titel || "");
    const content = String(payload && payload.inhalt || "");
    const link = String(payload && payload.link || "");

    let planObj = null;

    if (existing && rowIndexByKey[String(existing.plan_id)]) {
      const rowIndex = rowIndexByKey[String(existing.plan_id)];
      setCell_(shP, header, rowIndex, "titel", title);
      setCell_(shP, header, rowIndex, "inhalt", content);
      setCell_(shP, header, rowIndex, "link", link);
      setCell_(shP, header, rowIndex, "updated_at", now);
      setCell_(shP, header, rowIndex, "updated_by", String(user.trainer_id));

      planObj = {
        plan_id: String(existing.plan_id || ""),
        training_id: String(trainingId),
        titel: title,
        inhalt: content,
        link,
        created_at: existing.created_at || "",
        created_by: String(existing.created_by || ""),
        updated_at: now,
        updated_by: String(user.trainer_id),
      };
    } else {
      const plan_id = "P_" + createShortId_();
      planObj = {
        plan_id,
        training_id: String(trainingId),
        titel: title,
        inhalt: content,
        link,
        created_at: now,
        created_by: String(user.trainer_id),
        updated_at: now,
        updated_by: String(user.trainer_id),
        deleted_at: "",
      };

      appendRow_(shP, planObj);
    }

    return {
      ok: true,
      plan: {
        plan_id: planObj.plan_id,
        training_id: planObj.training_id,
        titel: planObj.titel,
        inhalt: planObj.inhalt,
        link: planObj.link,
        created_at: dtStr_(planObj.created_at),
        created_by: planObj.created_by,
        updated_at: dtStr_(planObj.updated_at),
        updated_by: planObj.updated_by,
      },
    };
  } finally {
    lock.releaseLock();
  }
}

function apiAdminDeleteTrainingPlan(token, planId) {
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    const user = requireSession_(token);
    if (!user.is_admin) return { ok: false, error: "Nur Admin." };

    const ss = getSS_();
    const shP = ss.getSheetByName(CFG.SHEETS.TRAININGSPLAN);
    if (!shP) return { ok: false, error: `Sheet fehlt: ${CFG.SHEETS.TRAININGSPLAN}` };

    const { header, rowIndexByKey } = readTableWithMeta_(shP, "plan_id");
    const rowIndex = rowIndexByKey[String(planId)];
    if (!rowIndex) return { ok: false, error: "Trainingsplan nicht gefunden." };

    const now = new Date();
    setCell_(shP, header, rowIndex, "deleted_at", now);
    setCell_(shP, header, rowIndex, "updated_at", now);
    setCell_(shP, header, rowIndex, "updated_by", String(user.trainer_id));

    return { ok: true };
  } finally {
    lock.releaseLock();
  }
}

function apiBillingHalfyear(token, year, half) {
  const user = requireSession_(token);
  const ss = getSS_();
  const shT = ss.getSheetByName(CFG.SHEETS.TRAININGS);
  const shE = ss.getSheetByName(CFG.SHEETS.EINTEILUNGEN);

  const trainings = readTable_(shT, { displayCols: ["start", "ende"] });
  const einteilungen = readTable_(shE);

  const y = Number(year);
  const h = String(half); // "H1"|"H2"
  if (!y || !["H1","H2"].includes(h)) return { ok:false, error:"Ungültiges Halbjahr/Jahr." };

  const start = (h === "H1") ? new Date(y,0,1) : new Date(y,6,1);
  const end   = (h === "H1") ? new Date(y,6,1) : new Date(y+1,0,1);

  // Alle Trainings im Halbjahr (egal ob geplant oder stattgefunden)
  const tMapAll = new Map();
  trainings.forEach(t => {
    const d = toDate_(t.datum);
    if (!d) return;
    const sd = startOfDay_(d);
    if (sd < start || sd >= end) return;
    tMapAll.set(String(t.training_id), t);
  });

  // Map für abgerechnete Trainings (stattgefunden)
  const tMapAbgerechnet = new Map();
  trainings.forEach(t => {
    const d = toDate_(t.datum);
    if (!d) return;
    const sd = startOfDay_(d);
    if (String(t.status) !== "stattgefunden") return;
    if (sd < start || sd >= end) return;
    tMapAbgerechnet.set(String(t.training_id), t);
  });

  const items = [];
  const pending = [];
  let totalHours = 0;
  let totalAmount = 0;
  let pendingTotalHours = 0;
  let pendingTotalAmount = 0;
  let paidTotalAmount = 0;
  let possibleTotalAmount = 0;
  let paidTotalHours = 0;
  let possibleTotalHours = 0;

  einteilungen.forEach(e => {
    if (String(e.trainer_id) !== user.trainer_id) return;
    if (isBlank_(e.ausgetragen_am)) {
      // Noch nicht ausgetragen
      const trainingId = String(e.training_id);

      if (tMapAll.has(trainingId)) {
        const trAll = tMapAll.get(trainingId);
        const hours = hoursBetween_(trAll.start, trAll.ende);
        const rate = Number(user.stundensatz || 0);

        const satzAmount = (e && e.satz_eur !== undefined && e.satz_eur !== "")
          ? Number(e.satz_eur) || 0
          : (trAll && trAll.satz_eur !== undefined && trAll.satz_eur !== "")
            ? Number(trAll.satz_eur) || 0
            : round2_(hours * rate);

        possibleTotalAmount += satzAmount;
        possibleTotalHours += hours;
      }

      // Abgerechnete Items
      if (tMapAbgerechnet.has(trainingId) && String(e.attendance || "") === "JA") {
        const tr = tMapAbgerechnet.get(trainingId);
        const hours = hoursBetween_(tr.start, tr.ende);
        const rate = Number(user.stundensatz || 0);

        // Prefer explicit betrag_eur from Einteilungen sheet, then training, then compute
        let amount = 0;
        if (e && e.betrag_eur !== undefined && e.betrag_eur !== "") {
          amount = Number(e.betrag_eur) || 0;
        } else if (tr && tr.betrag_eur !== undefined && tr.betrag_eur !== "") {
          amount = Number(tr.betrag_eur) || 0;
        } else {
          amount = round2_(hours * rate);
        }

        items.push({
          training_id: trainingId,
          datum: formatDate_(toDate_(tr.datum)),
          gruppe: String(tr.gruppe || ""),
          ort: String(tr.ort || ""),
          start: fmtTime_(tr.start),
          ende: fmtTime_(tr.ende),
          hours,
          rate,
          amount,
          checkin_am: e.checkin_am ? formatDateTime_(toDate_(e.checkin_am)) : "",
          status: "abgerechnet",
        });

        totalHours += hours;
        totalAmount += amount;

        const paidAmount = (e && e.betrag_eur !== undefined && e.betrag_eur !== "")
          ? Number(e.betrag_eur) || 0
          : (tr && tr.betrag_eur !== undefined && tr.betrag_eur !== "")
            ? Number(tr.betrag_eur) || 0
            : round2_(hours * rate);

        paidTotalAmount += paidAmount;
        paidTotalHours += hours;
      }
      // Ausstehende Items (noch nicht abgerechnet)
      else if (tMapAll.has(trainingId)) {
        const tr = tMapAll.get(trainingId);
        const d = toDate_(tr.datum);
        const hours = hoursBetween_(tr.start, tr.ende);
        const rate = Number(user.stundensatz || 0);

        // Prefer satz_eur from Einteilungen, then training, then compute
        let amount = 0;
        if (e && e.satz_eur !== undefined && e.satz_eur !== "") {
          amount = Number(e.satz_eur) || 0;
        } else if (tr && tr.satz_eur !== undefined && tr.satz_eur !== "") {
          amount = Number(tr.satz_eur) || 0;
        } else {
          amount = round2_(hours * rate);
        }

        let status_text = "";
        if (String(tr.status) !== "stattgefunden") {
          status_text = `Training noch ${tr.status}`;
        } else if (String(e.attendance || "") !== "JA") {
          status_text = "Check-in erforderlich";
        }

        pending.push({
          training_id: trainingId,
          datum: formatDate_(d),
          gruppe: String(tr.gruppe || ""),
          ort: String(tr.ort || ""),
          start: fmtTime_(tr.start),
          ende: fmtTime_(tr.ende),
          hours,
          rate,
          amount,
          checkin_am: e.checkin_am ? formatDateTime_(toDate_(e.checkin_am)) : "",
          status: status_text,
          trainingstatus: String(tr.status || ""),
          attendance: String(e.attendance || ""),
        });

        // accumulate pending totals
        pendingTotalHours += hours;
        pendingTotalAmount += amount;
      }
    }
  });

  totalHours = round2_(totalHours);
  totalAmount = round2_(totalAmount);
  pendingTotalHours = round2_(pendingTotalHours);
  pendingTotalAmount = round2_(pendingTotalAmount);
  paidTotalAmount = round2_(paidTotalAmount);
  possibleTotalAmount = round2_(possibleTotalAmount);
  paidTotalHours = round2_(paidTotalHours);
  possibleTotalHours = round2_(possibleTotalHours);

  items.sort((a,b) => a.datum.localeCompare(b.datum, "de"));
  pending.sort((a,b) => a.datum.localeCompare(b.datum, "de"));

  return { ok:true, items, pending, totalHours, totalAmount, pendingTotalHours, pendingTotalAmount, paidTotalAmount, paidTotalHours, possibleTotalAmount, possibleTotalHours };
}

/** ====== Nicht verfügbar ====== */
function apiSetUnavailable(token, trainingId, grund) {
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    const user = requireSession_(token);
    const ss = getSS_();

    const shA = ss.getSheetByName(CFG.SHEETS.ABMELDUNGEN);
    if (!shA) return { ok:false, error:"Sheet ABMELDUNGEN fehlt." };

    const tid = String(trainingId || "").trim();
    if (!tid) return { ok:false, error:"training_id fehlt." };

    // not allowed if already assigned
    const einteilungen = readTable_(ss.getSheetByName(CFG.SHEETS.EINTEILUNGEN));
    const alreadyAssigned = einteilungen.some(e =>
      String(e.training_id) === tid &&
      String(e.trainer_id) === String(user.trainer_id) &&
      isBlank_(e.ausgetragen_am)
    );
    if (alreadyAssigned) return { ok:false, error:"Du bist bereits eingeteilt. Bitte erst austragen." };

    const abm = readTableSafe_(shA);
    const already = abm.some(a =>
      String(a.training_id) === tid &&
      String(a.trainer_id) === String(user.trainer_id) &&
      isBlank_(a.deleted_at)
    );
    if (already) return { ok:true };

    appendRow_(shA, {
      abmeldung_id: "A_" + createShortId_(),
      training_id: tid,
      trainer_id: String(user.trainer_id),
      grund: String(grund || ""),
      created_at: new Date(),
      deleted_at: "",
    });

    return { ok:true };
  } finally {
    lock.releaseLock();
  }
}

function apiUnsetUnavailable(token, trainingId) {
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    const user = requireSession_(token);
    const ss = getSS_();

    const shA = ss.getSheetByName(CFG.SHEETS.ABMELDUNGEN);
    if (!shA) return { ok:false, error:"Sheet ABMELDUNGEN fehlt." };

    const tid = String(trainingId || "").trim();
    if (!tid) return { ok:false, error:"training_id fehlt." };

    const values = shA.getDataRange().getValues();
    if (values.length < 2) return { ok:true };

    const header = values[0].map(h => String(h).trim());
    const cTid = header.indexOf("training_id");
    const cUid = header.indexOf("trainer_id");
    const cDel = header.indexOf("deleted_at");
    if (cTid < 0 || cUid < 0 || cDel < 0) return { ok:false, error:"ABMELDUNGEN Header unvollständig." };

    for (let r = values.length - 1; r >= 1; r--) {
      const rt = String(values[r][cTid] || "");
      const ru = String(values[r][cUid] || "");
      const del = values[r][cDel];
      if (rt === tid && ru === String(user.trainer_id) && isBlank_(del)) {
        shA.getRange(r+1, cDel+1).setValue(new Date());
        return { ok:true };
      }
    }
    return { ok:true };
  } finally {
    lock.releaseLock();
  }
}

/** ====== Helpers (Sheets) ====== */
function getSS_() {
  if (_cachedSs) return _cachedSs;
  if (CFG.SPREADSHEET_ID) {
    _cachedSs = SpreadsheetApp.openById(CFG.SPREADSHEET_ID);
  } else {
    _cachedSs = SpreadsheetApp.getActiveSpreadsheet();
  }
  return _cachedSs;
}

function readTable_(sheet, opts) {
  const range = sheet.getDataRange();
  const values = range.getValues();
  if (!values || values.length < 2) return [];

  const displays = range.getDisplayValues();
  const header = values[0].map(h => String(h).trim());
  const useDisplay = new Set(((opts && opts.displayCols) || []).map(x => String(x).trim()));

  const rows = [];
  for (let r = 1; r < values.length; r++) {
    const obj = {};
    for (let c = 0; c < header.length; c++) {
      const col = header[c];
      obj[col] = useDisplay.has(col) ? displays[r][c] : values[r][c];
    }
    rows.push(obj);
  }
  return rows;
}

function readTableSafe_(sheet, opts) {
  try {
    if (!sheet) return [];
    return readTable_(sheet, opts);
  } catch (e) {
    return [];
  }
}

function readTableWithMeta_(sheet, keyCol) {
  const values = sheet.getDataRange().getValues();
  const header = values[0].map(h => String(h).trim());
  const keyIdx = header.indexOf(keyCol);
  if (keyIdx === -1) throw new Error(`Key column not found: ${keyCol}`);

  const rowIndexByKey = {};
  for (let r = 1; r < values.length; r++) {
    const key = String(values[r][keyIdx] || "").trim();
    if (key) rowIndexByKey[key] = r + 1; // 1-based row
  }
  return { header, values, rowIndexByKey };
}

function ensureColumns_(sheet, colNames) {
  if (!sheet) throw new Error("Sheet fehlt");
  const headerRange = sheet.getRange(1, 1, 1, sheet.getLastColumn());
  const headerValues = headerRange.getValues()[0].map((h) => String(h).trim());
  let current = headerValues.slice();

  (colNames || []).forEach((col) => {
    if (current.indexOf(col) === -1) {
      current.push(col);
      sheet.getRange(1, current.length, 1, 1).setValue(col);
    }
  });

  return current;
}

function appendRow_(sheet, obj) {
  // Header lesen
  const header = sheet
    .getRange(1, 1, 1, sheet.getLastColumn())
    .getValues()[0]
    .map(h => String(h).trim());

  if (!header.length) throw new Error("appendRow_: Keine Header-Zeile gefunden.");

  // Wir nutzen die 1. Spalte als "Key"-Spalte (z.B. einteilung_id / abmeldung_id)
  // Wichtig: In deinen Tabellen steht die ID-Spalte üblicherweise ganz links.
  const keyColIndex = 1; // 1-based
  const maxRows = sheet.getMaxRows();

  // Werte der Key-Spalte ab Zeile 2 laden (Formeln in anderen Spalten sind egal)
  const keyVals = sheet.getRange(2, keyColIndex, maxRows - 1, 1).getValues();

  // Erste wirklich freie Zeile finden (Key-Zelle leer)
  let targetRow = -1;
  for (let i = 0; i < keyVals.length; i++) {
    const v = keyVals[i][0];
    if (v === null || v === undefined || String(v).trim() === "") {
      targetRow = i + 2; // +2 wegen Start bei Zeile 2
      break;
    }
  }

  // Falls keine freie Zeile gefunden wurde: ans Ende anhängen
  if (targetRow === -1) {
    targetRow = sheet.getLastRow() + 1;
    if (targetRow > maxRows) sheet.insertRowAfter(maxRows);
  }

  // ✅ Nur Spalten schreiben, die im obj vorhanden sind
  // Dadurch bleiben Formeln in anderen Spalten erhalten (werden nicht überschrieben).
  for (let c = 0; c < header.length; c++) {
    const colName = header[c];
    if (Object.prototype.hasOwnProperty.call(obj, colName)) {
      sheet.getRange(targetRow, c + 1).setValue(obj[colName]);
    }
  }
}


function setCellIfNoFormula_(sheet, header, rowIndex, colName, value) {
  const colIndex = header.indexOf(colName);
  if (colIndex === -1) return false;
  const cell = sheet.getRange(rowIndex, colIndex + 1);
  const formula = cell.getFormula();
  if (formula && String(formula).trim() !== "") return false;
  cell.setValue(value);
  return true;
}

function setCell_(sheet, header, rowIndex, colName, value) {
  const colIndex = header.indexOf(colName);
  if (colIndex === -1) throw new Error(`Column not found: ${colName}`);
  sheet.getRange(rowIndex, colIndex + 1).setValue(value);
}

function safeSetCell_(sheet, header, rowIndex, colName, value) {
  const colIndex = header.indexOf(colName);
  if (colIndex === -1) return false;
  sheet.getRange(rowIndex, colIndex + 1).setValue(value);
  return true;
}

function getCell_(sheet, header, rowIndex, colName) {
  const colIndex = header.indexOf(colName);
  if (colIndex === -1) throw new Error(`Column not found: ${colName}`);
  return sheet.getRange(rowIndex, colIndex + 1).getValue();
}

/** ====== Helpers (Time/Format) ====== */
function toDate_(v) {
  if (!v) return null;
  if (v instanceof Date) return v;
  const d = new Date(v);
  return isNaN(d.getTime()) ? null : d;
}

function startOfDay_(d) {
  const x = new Date(d);
  x.setHours(0,0,0,0);
  return x;
}

function formatDate_(d) {
  if (!d) return "";
  return Utilities.formatDate(d, CFG.TIMEZONE, "dd.MM.yyyy");
}

function formatDateTime_(d) {
  if (!d) return "";
  return Utilities.formatDate(d, CFG.TIMEZONE, "dd.MM.yyyy HH:mm");
}

function dtStr_(v) {
  if (!v) return "";
  if (v instanceof Date) return formatDateTime_(v);
  const d = toDate_(v);
  return d ? formatDateTime_(d) : String(v);
}

function fmtTime_(v) {
  if (v === null || v === undefined) return "";
  const raw = String(v).trim();
  if (raw === "") return "";

  if (v instanceof Date) {
    const h = v.getHours();
    const m = v.getMinutes();
    const pad2 = (n) => String(n).padStart(2, "0");
    return pad2(h) + ":" + pad2(m);
  }

  if (typeof v === "number") {
    const minutes = Math.round((v % 1) * 24 * 60);
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return String(h).padStart(2, "0") + ":" + String(m).padStart(2, "0");
  }

  const hm = raw.match(/^(\d{1,2}):(\d{2})/);
  if (hm) return String(Number(hm[1])).padStart(2, "0") + ":" + hm[2];

  const d = new Date(raw);
  if (!isNaN(d.getTime())) return Utilities.formatDate(d, CFG.TIMEZONE, "HH:mm");

  return raw;
}

function parseHM_(v) {
  if (v === null || v === undefined) return [null, null];
  const raw = String(v).trim();
  if (raw === "") return [null, null];

  if (v instanceof Date) return [v.getHours(), v.getMinutes()];

  if (typeof v === "number") {
    const minutes = Math.round((v % 1) * 24 * 60);
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return [h, m];
  }

  const m = raw.match(/^(\d{1,2}):(\d{2})/);
  if (!m) return [null, null];
  return [Number(m[1]), Number(m[2])];
}

function hoursBetween_(start, end) {
  const [sh, sm] = parseHM_(start);
  const [eh, em] = parseHM_(end);
  if (sh === null || eh === null) return 0;
  const s = sh * 60 + sm;
  const e = eh * 60 + em;
  const diff = Math.max(0, e - s);
  return round2_(diff / 60);
}

function round2_(n) {
  return Math.round(Number(n || 0) * 100) / 100;
}

function truthy_(v) {
  if (v === true) return true;
  if (v === false) return false;
  const s = String(v || "").toUpperCase().trim();
  return ["TRUE", "WAHR", "1", "JA", "YES", "X"].includes(s);
}

function isBlank_(v) {
  return v === null || v === undefined || String(v).trim() === "";
}

/** ====== PIN Hashing ====== */
function hashPin_(pin) {
  const normalized = String(pin || "").trim();
  if (!normalized) return "";
  const digest = Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, normalized, Utilities.Charset.UTF_8);
  return `sha256:${Utilities.base64Encode(digest)}`;
}

function isHashedPin_(value) {
  const normalized = typeof value === "string" ? value.trim() : "";
  if (!normalized) return false;
  if (normalized.startsWith("sha256:")) return true;
  if (normalized.startsWith("sha256hex:")) return true;
  if (/^[0-9a-f]{64}$/i.test(normalized)) return true;
  return false;
}

function verifyPin_(input, stored) {
  const candidate = String(input || "").trim();
  const expected = String(stored || "").trim();
  if (!candidate || !expected) return false;

  if (expected.startsWith("sha256:")) {
    return hashPin_(candidate) === expected;
  }

  const digest = Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, candidate, Utilities.Charset.UTF_8);
  const digestHex = digest.map((b) => {
    const v = (b < 0 ? b + 256 : b).toString(16);
    return v.length === 1 ? `0${v}` : v;
  }).join("");

  if (expected.startsWith("sha256hex:")) {
    const needle = expected.replace(/^sha256hex:/i, "").trim();
    return digestHex.toLowerCase() === needle.toLowerCase();
  }

  if (/^[0-9a-f]{64}$/i.test(expected)) {
    return digestHex.toLowerCase() === expected.toLowerCase();
  }

  // Fallback für Legacy-Daten vor der Migration
  return candidate === expected;
}

function generateRandomPin_() {
  const length = 4 + Math.floor(Math.random() * 3); // 4, 5 oder 6 Stellen
  let pin = "";
  for (let i = 0; i < length; i++) {
    pin += Math.floor(Math.random() * 10);
  }
  return pin;
}

/** ====== Session ====== */
function createToken_() {
  return Utilities.getUuid();
}

function saveSession_(token, data) {
  const props = PropertiesService.getUserProperties();
  props.setProperty(token, JSON.stringify({
    ...data,
    exp: Date.now() + CFG.SESSION_TTL_SECONDS * 1000
  }));
}

function getSession_(token) {
  if (!token) return null;
  const props = PropertiesService.getUserProperties();
  const raw = props.getProperty(token);
  if (!raw) return null;
  try {
    const obj = JSON.parse(raw);
    if (Date.now() > Number(obj.exp || 0)) return null;
    return obj;
  } catch (e) {
    return null;
  }
}

function clearSession_(token) {
  if (!token) return;
  PropertiesService.getUserProperties().deleteProperty(token);
}

function requireSession_(token) {
  const s = getSession_(token);
  if (!s) throw new Error("Session abgelaufen. Bitte neu einloggen.");
  return s;
}

function requireAdmin_(token) {
  const s = requireSession_(token);
  if (!truthy_(s.is_admin)) throw new Error("Nur Admin.");
  return s;
}

/** ====== Admin Dashboard & Trainings ====== */
function apiAdminDashboard(token) {
  try {
    requireAdmin_(token);
    const ss = getSS_();
    const shT = ss.getSheetByName(CFG.SHEETS.TRAININGS);
    const shE = ss.getSheetByName(CFG.SHEETS.EINTEILUNGEN);
    if (!shT) return { ok: false, error: `Sheet fehlt: ${CFG.SHEETS.TRAININGS}` };
    if (!shE) return { ok: false, error: `Sheet fehlt: ${CFG.SHEETS.EINTEILUNGEN}` };

    const trainings = readTable_(shT, { displayCols: ["start", "ende"] });
    const einteilungen = readTable_(shE);

    const headerVals = shT.getLastColumn() > 0
      ? shT.getRange(1, 1, 1, shT.getLastColumn()).getValues()[0]
      : [];
    const header = headerVals.map((h) => String(h).trim());
    const hasDeleted = header.includes("deleted_at");

    const today = startOfDay_(new Date());
    const upcoming = trainings.filter((tr) => {
      const d = toDate_(tr.datum);
      if (!d) return false;
      if (hasDeleted && !isBlank_(tr.deleted_at)) return false;
      const ts = startOfDay_(d).getTime();
      const status = String(tr.status || "").toLowerCase();
      return ts >= today.getTime() && status !== "ausgefallen";
    });

    const openSlotsCount = upcoming.reduce((sum, tr) => sum + (enrichTraining_(tr, einteilungen).offen || 0), 0);

    const weekStart = startOfDay_(new Date(today));
    const weekday = weekStart.getDay();
    const diff = weekday === 0 ? -6 : 1 - weekday;
    weekStart.setDate(weekStart.getDate() + diff);
    const weekEnd = startOfDay_(new Date(weekStart));
    weekEnd.setDate(weekEnd.getDate() + 6);

    const trainingsThisWeekCount = trainings.filter((tr) => {
      const d = toDate_(tr.datum);
      if (!d) return false;
      if (hasDeleted && !isBlank_(tr.deleted_at)) return false;
      const ts = startOfDay_(d).getTime();
      return ts >= weekStart.getTime() && ts <= weekEnd.getTime();
    }).length;

    const openCheckinsCount = trainings
      .filter((tr) => {
        if (hasDeleted && !isBlank_(tr.deleted_at)) return false;
        return String(tr.status || "").toLowerCase() === "stattgefunden";
      })
      .reduce((sum, tr) => {
        const tid = String(tr.training_id || "");
        const missing = einteilungen.filter((e) =>
          String(e.training_id) === tid &&
          isBlank_(e.ausgetragen_am) &&
          String(e.attendance || "").toUpperCase() !== "JA"
        ).length;
        return sum + missing;
      }, 0);

    return {
      ok: true,
      upcomingCount: upcoming.length,
      openSlotsCount,
      openCheckinsCount,
      trainingsThisWeekCount,
    };
  } catch (e) {
    return { ok: false, error: e && e.message ? e.message : String(e) };
  }
}

function apiAdminListTrainings(token, filters) {
  try {
    requireAdmin_(token);
    const ss = getSS_();
    const shT = ss.getSheetByName(CFG.SHEETS.TRAININGS);
    if (!shT) return { ok: false, error: `Sheet fehlt: ${CFG.SHEETS.TRAININGS}` };

    const headerVals = shT.getLastColumn() > 0
      ? shT.getRange(1, 1, 1, shT.getLastColumn()).getValues()[0]
      : [];
    const header = headerVals.map((h) => String(h).trim());
    const hasDeleted = header.includes("deleted_at");

    const trainings = readTable_(shT, { displayCols: ["start", "ende"] });
    const monthFilter = String(filters && filters.month ? filters.month : "").trim();
    const statusFilter = String(filters && filters.status ? filters.status : "").trim();

    const items = trainings
      .filter((tr) => {
        if (hasDeleted && !isBlank_(tr.deleted_at)) return false;
        if (statusFilter && String(tr.status || "") !== statusFilter) return false;
        if (monthFilter) {
          const d = toDate_(tr.datum);
          const mKey = d ? `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}` : "";
          if (mKey !== monthFilter) return false;
        }
        return true;
      })
      .map((tr) => {
        const d = toDate_(tr.datum);
        const iso = d ? Utilities.formatDate(d, CFG.TIMEZONE, "yyyy-MM-dd") : String(tr.datum || "");
        return {
          training_id: String(tr.training_id || ""),
          datum: d ? formatDate_(d) : String(tr.datum || ""),
          datum_iso: iso,
          datumTs: d ? startOfDay_(d).getTime() : 0,
          start: fmtTime_(tr.start),
          ende: fmtTime_(tr.ende),
          start_raw: fmtTime_(tr.start),
          ende_raw: fmtTime_(tr.ende),
          gruppe: String(tr.gruppe || ""),
          ort: String(tr.ort || ""),
          status: String(tr.status || ""),
          benoetigt_trainer: tr.benoetigt_trainer,
          ausfall_grund: String(tr.ausfall_grund || ""),
        };
      })
      .sort((a, b) => a.datumTs - b.datumTs);

    return { ok: true, items };
  } catch (e) {
    return { ok: false, error: e && e.message ? e.message : String(e) };
  }
}

function apiAdminUpsertTraining(token, payload) {
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    const me = requireAdmin_(token);
    const ss = getSS_();
    const sh = ss.getSheetByName(CFG.SHEETS.TRAININGS);
    if (!sh) return { ok: false, error: `Sheet fehlt: ${CFG.SHEETS.TRAININGS}` };

    const { header, rowIndexByKey } = readTableWithMeta_(sh, "training_id");
    const training_id = String(payload && payload.training_id ? payload.training_id : "").trim() || createShortId_();
    const rowIndex = rowIndexByKey[training_id];

    const d = payload && payload.datum ? toDate_(payload.datum) || payload.datum : "";
    const obj = {
      training_id,
      datum: d,
      start: payload ? payload.start : "",
      ende: payload ? payload.ende : "",
      gruppe: payload ? payload.gruppe : "",
      ort: payload ? payload.ort : "",
      status: payload ? payload.status || "geplant" : "geplant",
      benoetigt_trainer: payload ? payload.benoetigt_trainer : "",
      ausfall_grund: payload ? payload.ausfall_grund : "",
    };

    if (rowIndex) {
      Object.keys(obj).forEach((key) => safeSetCell_(sh, header, rowIndex, key, obj[key]));
      safeSetCell_(sh, header, rowIndex, "updated_at", new Date());
      safeSetCell_(sh, header, rowIndex, "updated_by", me && me.name ? me.name : me.trainer_id);
    } else {
      appendRow_(sh, obj);
    }

    return { ok: true, training_id };
  } catch (e) {
    return { ok: false, error: e && e.message ? e.message : String(e) };
  } finally {
    lock.releaseLock();
  }
}

function apiAdminDeleteTraining(token, training_id) {
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    requireAdmin_(token);
    const ss = getSS_();
    const sh = ss.getSheetByName(CFG.SHEETS.TRAININGS);
    if (!sh) return { ok: false, error: `Sheet fehlt: ${CFG.SHEETS.TRAININGS}` };

    const { header, rowIndexByKey } = readTableWithMeta_(sh, "training_id");
    const rowIndex = rowIndexByKey[String(training_id || "").trim()];
    if (!rowIndex) return { ok: false, error: "Training nicht gefunden." };

    const didDelete = safeSetCell_(sh, header, rowIndex, "deleted_at", new Date());
    if (!didDelete) {
      safeSetCell_(sh, header, rowIndex, "status", "ausgefallen");
      safeSetCell_(sh, header, rowIndex, "ausfall_grund", "gelöscht");
    }

    return { ok: true };
  } catch (e) {
    return { ok: false, error: e && e.message ? e.message : String(e) };
  } finally {
    lock.releaseLock();
  }
}

function createShortId_() {
  return Math.random().toString(16).slice(2, 10);
}

/** ====== Enrichment ====== */
function enrichTraining_(tr, einteilungen) {
  const trainingId = String(tr.training_id);
  const activeCount = einteilungen.filter(e =>
    String(e.training_id) === trainingId &&
    isBlank_(e.ausgetragen_am)
  ).length;

  const needed = Number(tr.benoetigt_trainer || 0);
  const offen = Math.max(0, needed - activeCount);

  const d = toDate_(tr.datum);
  return {
    training_id: trainingId,
    datum: d ? formatDate_(d) : "",
    datumTs: d ? startOfDay_(d).getTime() : 0,
    start: fmtTime_(tr.start),
    ende: fmtTime_(tr.ende),
    gruppe: String(tr.gruppe || ""),
    ort: String(tr.ort || ""),
    status: String(tr.status || ""),
    benoetigt_trainer: needed,
    eingeteilt: activeCount,
    offen,
    offen_text: `Noch ${offen} Trainer`,
    ausfall_grund: String(tr.ausfall_grund || ""),
  };
}

function enrichEinteilung_(e, trainings) {
  const tr = trainings.find(x => String(x.training_id) === String(e.training_id));
  const d = tr ? toDate_(tr.datum) : null;

  const start = tr ? fmtTime_(tr.start) : "";
  const ende  = tr ? fmtTime_(tr.ende) : "";

  return {
    einteilung_id: String(e.einteilung_id || ""),
    training_id: String(e.training_id || ""),

    datum: d ? formatDate_(d) : "",
    training_datum: d ? formatDate_(d) : "",
    trainingDatumTs: d ? startOfDay_(d).getTime() : 0,

    start,
    ende,
    gruppe: tr ? String(tr.gruppe || "") : "",
    ort: tr ? String(tr.ort || "") : "",
    training_status: tr ? String(tr.status || "") : "",

    training_label: tr
      ? `${formatDate_(d)} · ${start}–${ende} · ${String(tr.gruppe || "")}`
      : String(e.training_id || ""),

    rolle: String(e.rolle || ""),
    attendance: String(e.attendance || ""),
    checkin_am: e.checkin_am ? formatDateTime_(toDate_(e.checkin_am)) : "",
    ausgetragen: !isBlank_(e.ausgetragen_am),
  };
}

/** ====== Admin Users (existiert bei dir schon) ====== */
function TEST_listTrainers(){
  const ss = getSS_();
  const rows = readTable_(ss.getSheetByName(CFG.SHEETS.TRAINER));
  Logger.log(rows);
}

function apiAdminListRoles(token) {
  try {
    requireAdmin_(token);

    const rates = Array.from(getRoleRates_().values())
      .map((r) => ({ rolle: r.role, stundensatz_eur: r.rate, abrechenbar: r.billable }));

    return {
      ok: true,
      items: rates.sort((a, b) => String(a.rolle || "").localeCompare(String(b.rolle || ""), "de")),
    };
  } catch (e) {
    return { ok: false, error: e && e.message ? e.message : String(e) };
  }
}

function apiAdminListTrainers(token){
  try {
    requireAdmin_(token);

    const ss = getSS_();
    const sh = ss.getSheetByName(CFG.SHEETS.TRAINER);
    if (!sh) return { ok:false, error:`Sheet fehlt: ${CFG.SHEETS.TRAINER}` };

    const roleRates = getRoleRates_();
    const rows = readTable_(sh);
    return { ok:true, items: rows.map(r=>{
      const ll = r.last_login;
      const rolle_standard = String(r.rolle_standard || "").trim();
      const roleRate = rolle_standard ? roleRates.get(rolle_standard) : null;
      const effectiveRate = roleRate ? roleRate.rate : Number(r.stundensatz_eur ?? r.stundensatz ?? 0);
      return {
        trainer_id: String(r.trainer_id||""),
        name: String(r.name||""),
        email: String(r.email||""),
        aktiv: String(r.aktiv||"TRUE"),
        is_admin: String(r.is_admin||"FALSE"),
        rolle_standard,
        stundensatz: String(effectiveRate ?? "0"),
        stundensatz_eur: Number(effectiveRate ?? 0),
        stundensatz_eur_effective: Number(effectiveRate ?? 0),
        last_login: ll ? (ll instanceof Date ? formatDateTime_(ll) : String(ll)) : "",
      };
    }).sort((a,b)=>a.name.localeCompare(b.name,"de")) };
  } catch (e) {
    return { ok:false, error: e && e.message ? e.message : String(e) };
  }
}

function apiAdminUpsertTrainer(token, payload){
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    requireAdmin_(token);

    const ss = getSS_();
    const sh = ss.getSheetByName(CFG.SHEETS.TRAINER);
    if (!sh) return { ok:false, error:`Sheet fehlt: ${CFG.SHEETS.TRAINER}` };

    const roleRates = getRoleRates_();

    const trainer_id = String(payload.trainer_id||"").trim();
    if (!trainer_id) return { ok:false, error:"trainer_id fehlt." };

    const rolle_standard = String(payload.rolle_standard || "").trim();
    if (!rolle_standard) return { ok:false, error:"rolle_standard fehlt." };

    const roleRate = roleRates.get(rolle_standard);
    if (!roleRate) return { ok:false, error:"Rolle nicht in ROLLEN_SAETZE gefunden." };

    const rate = Number(roleRate.rate || 0);

    // Tabelle lesen
    const data = sh.getDataRange().getValues();
    const headers = data[0].map(String);
    const idx = (name)=> headers.indexOf(name);

    const iTrainer = idx("trainer_id");
    if (iTrainer < 0) return { ok:false, error:"Spalte trainer_id fehlt im TRAINER-Tab." };

    let rowIndex = -1;
    for (let i=1;i<data.length;i++){
      if (String(data[i][iTrainer]).trim() === trainer_id){ rowIndex=i+1; break; }
    }

    const set = (colName, value)=>{
      const c = idx(colName);
      if (c<0) return;
      const r = rowIndex>0 ? rowIndex : (data.length+1);
      sh.getRange(r, c+1).setValue(value);
    };

    set("trainer_id", trainer_id);
    set("name", String(payload.name||""));
    set("email", String(payload.email||""));
    set("rolle_standard", rolle_standard);
    const newPin = String(payload.pin||"").trim();
    if (newPin) {
      set("pin", hashPin_(newPin));
    }
    set("stundensatz", rate);
    set("stundensatz_eur", rate);
    set("aktiv", String(payload.aktiv||"TRUE"));
    set("is_admin", String(payload.is_admin||"FALSE"));

    return { ok:true };
  } finally {
    lock.releaseLock();
  }
}

function apiAdminResetTrainerPin(token, trainer_id) {
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    requireAdmin_(token);

    const ss = getSS_();
    const sh = ss.getSheetByName(CFG.SHEETS.TRAINER);
    if (!sh) return { ok:false, error:`Sheet fehlt: ${CFG.SHEETS.TRAINER}` };

    const { header, rowIndexByKey } = readTableWithMeta_(sh, "trainer_id");
    const idx = rowIndexByKey[String(trainer_id)];
    if (!idx) return { ok:false, error:"Trainer nicht gefunden." };

    const newPin = generateRandomPin_();
    setCell_(sh, header, idx, "pin", hashPin_(newPin));

    return { ok:true, pin: newPin };
  } finally {
    lock.releaseLock();
  }
}

function apiAdminBillingOverview(token, year, half) {
  try {
    requireAdmin_(token);

    const targetYear = Number(year || new Date().getFullYear()) || new Date().getFullYear();
    const halfKey = String(half || "H1").toUpperCase() === "H2" ? "H2" : "H1";
    const startMonth = halfKey === "H1" ? 0 : 6;
    const endMonth = halfKey === "H1" ? 5 : 11;
    const rangeStart = new Date(targetYear, startMonth, 1);
    rangeStart.setHours(0, 0, 0, 0);
    const rangeEnd = new Date(targetYear, endMonth + 1, 0);
    rangeEnd.setHours(23, 59, 59, 999);

    const ss = getSS_();
    const shT = ss.getSheetByName(CFG.SHEETS.TRAININGS);
    const shE = ss.getSheetByName(CFG.SHEETS.EINTEILUNGEN);
    const shR = ss.getSheetByName(CFG.SHEETS.TRAINER);
    if (!shT) return { ok:false, error:`Sheet fehlt: ${CFG.SHEETS.TRAININGS}` };
    if (!shE) return { ok:false, error:`Sheet fehlt: ${CFG.SHEETS.EINTEILUNGEN}` };
    if (!shR) return { ok:false, error:`Sheet fehlt: ${CFG.SHEETS.TRAINER}` };

    const headerVals = shT.getLastColumn() > 0
      ? shT.getRange(1, 1, 1, shT.getLastColumn()).getValues()[0]
      : [];
    const headerT = headerVals.map((h) => String(h).trim());
    const hasDeleted = headerT.includes("deleted_at");

    const trainings = readTable_(shT, { displayCols: ["start", "ende"] })
      .filter((tr) => !hasDeleted || isBlank_(tr.deleted_at));
    const einteilungen = readTable_(shE);
    const trainers = readTable_(shR);
    const roleRates = getRoleRates_();

    const trainingMap = new Map();
    trainings.forEach((tr) => {
      const d = toDate_(tr.datum);
      if (!d) return;
      const ds = startOfDay_(d);
      const ts = ds.getTime();
      if (ts < rangeStart.getTime() || ts > rangeEnd.getTime()) return;
      trainingMap.set(String(tr.training_id || ""), { ...tr, datumDate: ds });
    });

    const trainerMap = new Map();
    trainers.forEach((t) => {
      const role = String(t.rolle_standard || "").trim();
      const roleRate = role ? roleRates.get(role) : null;
      const rate = roleRate ? Number(roleRate.rate || 0) : Number(t.stundensatz_eur ?? t.stundensatz ?? 0) || 0;
      trainerMap.set(String(t.trainer_id || ""), {
        trainer_id: String(t.trainer_id || ""),
        name: String(t.name || t.trainer_id || ""),
        rate,
      });
    });

    const summary = new Map();
    const ensureSummary = (trainerId) => {
      if (!summary.has(trainerId)) {
        const info = trainerMap.get(trainerId) || { trainer_id: trainerId, name: trainerId, rate: 0 };
        summary.set(trainerId, {
          trainer_id: trainerId,
          name: info.name,
          paidTotalAmount: 0,
          possibleTotalAmount: 0,
          openCheckinsCount: 0,
          rate: info.rate,
        });
      }
      return summary.get(trainerId);
    };

    einteilungen.forEach((e) => {
      if (!isBlank_(e.ausgetragen_am)) return;
      const tid = String(e.training_id || "");
      const tr = trainingMap.get(tid);
      if (!tr) return;
      const trainerId = String(e.trainer_id || "");
      const info = trainerMap.get(trainerId) || { rate: 0, name: trainerId };
      const sum = ensureSummary(trainerId);

      const hours = hoursBetween_(tr.start, tr.ende);
      const fallbackRate = info.rate || 0;
      const possibleAmount =
        e && e.satz_eur !== undefined && e.satz_eur !== ""
          ? Number(e.satz_eur) || 0
          : tr && tr.satz_eur !== undefined && tr.satz_eur !== ""
            ? Number(tr.satz_eur) || 0
            : round2_(hours * fallbackRate);
      sum.possibleTotalAmount += possibleAmount;

      const trainingStatus = String(tr.status || "").toLowerCase();
      const attendance = String(e.attendance || "").toUpperCase();
      if (trainingStatus === "stattgefunden") {
        if (attendance === "JA") {
          const paidAmount =
            e && e.betrag_eur !== undefined && e.betrag_eur !== ""
              ? Number(e.betrag_eur) || 0
              : tr && tr.betrag_eur !== undefined && tr.betrag_eur !== ""
                ? Number(tr.betrag_eur) || 0
                : round2_(hours * fallbackRate);
          sum.paidTotalAmount += paidAmount;
        } else {
          sum.openCheckinsCount += 1;
        }
      }
    });

    const items = Array.from(summary.values())
      .map((row) => ({
        trainer_id: row.trainer_id,
        name: row.name,
        paidTotalAmount: round2_(row.paidTotalAmount),
        possibleTotalAmount: round2_(row.possibleTotalAmount),
        openCheckinsCount: row.openCheckinsCount,
      }))
      .sort((a, b) => String(a.name || a.trainer_id).localeCompare(String(b.name || b.trainer_id), "de"));

    return { ok: true, items, half: halfKey, year: targetYear };
  } catch (e) {
    return { ok:false, error: e && e.message ? e.message : String(e) };
  }
}

function apiAdminMigratePins(token) {
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    requireAdmin_(token);
    const changed = migratePinsOnce_();
    return { ok: true, changed };
  } catch (e) {
    return { ok:false, error: e && e.message ? e.message : String(e) };
  } finally {
    lock.releaseLock();
  }
}

// Einmaliger Helfer zum Migrieren der bestehenden PINs auf Hash-Werte.
// Manuell ausführen (z.B. im Apps Script Editor), nicht als öffentliche API gedacht.
function ADMIN_migrateTrainerPinsToHashes() {
  const updated = migratePinsOnce_();
  return { ok: true, updated };
}

function migratePinsOnce_() {
  const ss = getSS_();
  const sh = ss.getSheetByName(CFG.SHEETS.TRAINER);
  if (!sh) throw new Error(`Sheet fehlt: ${CFG.SHEETS.TRAINER}`);

  const { header, values } = readTableWithMeta_(sh, "trainer_id");
  const pinIdx = header.indexOf("pin");
  if (pinIdx === -1) throw new Error("Spalte 'pin' nicht gefunden.");

  let updated = 0;
  for (let r = 1; r < values.length; r++) {
    const raw = String(values[r][pinIdx] || "").trim();
    if (!raw || isHashedPin_(raw)) continue;
    const hashed = hashPin_(raw);
    if (hashed) {
      sh.getRange(r + 1, pinIdx + 1).setValue(hashed);
      updated++;
    }
  }

  return updated;
}
