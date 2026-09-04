<?php
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>FSD · Europe · Data</title>
  <style>
    :root { color-scheme: dark; --bg:#070708; --card:#121214; --line:#2a2a2e; --muted:#9a9aa3; --red:#c41e3a; --ok:#34d399; }
    * { box-sizing: border-box; }
    body { margin:0; font-family: ui-sans-serif, system-ui, -apple-system, sans-serif; background:var(--bg); color:#f4f4f5; }
    .wrap { min-height:100dvh; padding:28px 20px 64px; background: radial-gradient(ellipse at 50% 0%, rgb(196 30 58 / .16), transparent 52%); }
    .inner { width:min(920px,100%); margin:0 auto; }
    h1 { font-size:2rem; font-weight:560; letter-spacing:-.03em; line-height:1.15; margin:12px 0 0; }
    .kicker { font-size:.72rem; letter-spacing:.18em; text-transform:uppercase; color:var(--muted); font-weight:600; }
    .muted { color:var(--muted); font-size:.92rem; line-height:1.5; }
    .row { display:flex; gap:10px; flex-wrap:wrap; margin-top:18px; }
    button, .btn { appearance:none; border:0; border-radius:999px; min-height:46px; padding:0 18px; font-weight:600; font-size:.95rem; cursor:pointer; }
    .primary { background:var(--red); color:#fff; }
    .ghost { background:#1c1c20; color:#fff; border:1px solid var(--line); }
    .card { background:var(--card); border:1px solid var(--line); border-radius:18px; padding:16px; margin-top:14px; }
    .grid { display:grid; gap:12px; grid-template-columns:1fr; }
    @media (min-width:720px) { .grid { grid-template-columns:1fr 1fr; } }
    input { width:100%; min-height:46px; border-radius:12px; border:1px solid var(--line); background:#0c0c0e; color:#fff; padding:0 12px; font-size:1rem; margin-top:8px; }
    .badge { display:inline-flex; border-radius:999px; padding:4px 10px; font-size:.75rem; background:#1c1c20; color:var(--muted); }
    .ok { color:var(--ok); }
    .warn { color:#fbbf24; }
    .name { font-size:1.15rem; font-weight:600; }
    .big { font-size:2rem; font-weight:650; letter-spacing:-.03em; }
    a { color:#fff; }
  </style>
</head>
<body>
  <div class="wrap"><div class="inner" id="app"></div></div>
  <script>
    const CLIENT_DEFAULT = "5ad68096-94cd-472e-ab99-b8781cf71e71";
    const AUTH = "https://auth.tesla.com/oauth2/v3/authorize";
    const REDIRECT = "https://fsd-europe-data.com/auth/callback";
    const SCOPES = "openid offline_access user_data vehicle_device_data vehicle_cmds vehicle_charging_cmds vehicle_location";
    const DEMO = [
      { name:"IJssel", model:"Model Y", city:"Zwolle", battery:72, status:"Parked" },
      { name:"Noorderlicht", model:"Model 3", city:"Amsterdam", battery:41, status:"Charging" },
      { name:"Schelde", model:"Model S", city:"Antwerpen", battery:63, status:"Driving" },
      { name:"Alster", model:"Model Y", city:"Hamburg", battery:18, status:"Parked" },
    ];
    const storeKey = "fsd-europe-data-hosting";
    const $ = (s) => document.querySelector(s);
    const app = $("#app");

    function load() {
      try { return JSON.parse(localStorage.getItem(storeKey) || "{}"); } catch { return {}; }
    }
    function save(p) { localStorage.setItem(storeKey, JSON.stringify({ ...load(), ...p })); }
    async function api(payload) {
      const res = await fetch("/api.php", { method:"POST", headers:{ "Content-Type":"application/json" }, body: JSON.stringify(payload) });
      return res.json();
    }
    function carCard(v) {
      return `<article class="card">
        <div style="display:flex;justify-content:space-between;gap:8px;align-items:center">
          <div><p class="name">${v.name}</p><p class="muted">${v.model || v.vin || ""} · ${v.city || v.state || ""}</p></div>
          <span class="badge">${v.status || v.charging || v.state || ""}</span>
        </div>
        <p class="big" style="margin:10px 0 0">${v.battery ?? "–"}%</p>
        <p class="muted">${v.rangeKm ? v.rangeKm + " km" : v.city || ""}</p>
      </article>`;
    }

    function home() {
      const s = load();
      app.innerHTML = `
        <p class="kicker">FSD · Europe · Data</p>
        <h1>Jouw Tesla.<br>Eén cockpit.</h1>
        <p class="muted" style="margin-top:12px">Demo-vloot werkt nu. Echte auto via Tesla Fleet API op dit domein.</p>
        <div class="row">
          <button class="primary" id="demo">Open demo-vloot</button>
          <button class="ghost" id="live">Koppel echte Tesla</button>
        </div>`;
      $("#demo").onclick = () => { save({ mode:"demo" }); cockpit(); };
      $("#live").onclick = setup;
      if (s.mode === "demo") cockpit();
      if (s.mode === "live" && s.accessToken) cockpit();
    }

    function setup() {
      const s = load();
      app.innerHTML = `
        <p class="kicker">Live koppelen</p>
        <h1>Berkant</h1>
        <div class="card">
          <p class="name">1. Public key</p>
          <p class="muted">Staat op dit domein. Tesla haalt hem zelf op.</p>
          <p class="muted" style="word-break:break-all;font-family:ui-monospace,monospace;font-size:12px">https://fsd-europe-data.com/.well-known/appspecific/com.tesla.3p.public-key.pem</p>
        </div>
        <div class="card">
          <p class="name">2. Client ID + Secret</p>
          <p class="muted">Tesla Developer → Berkant → Details. Niet in de chat plakken.</p>
          <input id="cid" value="${s.clientId || CLIENT_DEFAULT}" placeholder="Client ID" autocomplete="off" />
          <input id="sec" type="password" value="${s.clientSecret || ""}" placeholder="Client Secret" autocomplete="off" />
          <div class="row">
            <button class="primary" id="reg">Registreer bij Tesla</button>
          </div>
          <p class="muted" id="msg"></p>
        </div>
        <div class="card">
          <p class="name">3. Tesla-account</p>
          <p class="muted">Tesla stuurt je terug naar fsd-europe-data.com/auth/callback.</p>
          <button class="ghost" id="oauth">Tesla-account openen</button>
        </div>
        <button class="ghost" id="back" style="margin-top:14px;width:100%">Terug</button>`;
      const msg = $("#msg");
      $("#reg").onclick = async () => {
        const clientId = $("#cid").value.trim();
        const clientSecret = $("#sec").value.trim();
        save({ clientId, clientSecret });
        sessionStorage.setItem("tesla_secret", clientSecret);
        sessionStorage.setItem("tesla_id", clientId);
        msg.textContent = "Partner registreren…";
        const res = await api({ action:"register", clientId, clientSecret });
        msg.textContent = res.message || (res.ok ? "Geregistreerd." : "Mislukt.");
      };
      $("#oauth").onclick = () => {
        const clientId = $("#cid").value.trim() || CLIENT_DEFAULT;
        const clientSecret = $("#sec").value.trim();
        save({ clientId, clientSecret });
        sessionStorage.setItem("tesla_secret", clientSecret);
        sessionStorage.setItem("tesla_id", clientId);
        const u = new URL(AUTH);
        u.searchParams.set("client_id", clientId);
        u.searchParams.set("locale", "nl-NL");
        u.searchParams.set("prompt", "login");
        u.searchParams.set("redirect_uri", REDIRECT);
        u.searchParams.set("response_type", "code");
        u.searchParams.set("scope", SCOPES);
        u.searchParams.set("state", crypto.randomUUID());
        location.assign(u.toString());
      };
      $("#back").onclick = home;
    }

    async function cockpit() {
      const s = load();
      let cars = DEMO.map(v => ({ ...v, model:v.model }));
      let live = s.mode === "live";
      if (live && s.accessToken) {
        const res = await api({ action:"vehicles", accessToken:s.accessToken, region:s.region });
        if (res.ok && res.vehicles?.length) cars = res.vehicles;
      }
      app.innerHTML = `
        <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-end">
          <div>
            <p class="kicker">${live ? "Live vloot" : "Demo-vloot"}</p>
            <h1>${live ? "Jouw Tesla’s" : "Noordlicht Mobility"}</h1>
          </div>
          <button class="ghost" id="out">Terug</button>
        </div>
        <div class="grid" style="margin-top:18px">${cars.map(carCard).join("")}</div>`;
      $("#out").onclick = () => { save({ mode:"idle", accessToken:null }); home(); };
    }

    home();
  </script>
</body>
</html>
