<?php
header('Content-Type: text/html; charset=utf-8');
$code = isset($_GET['code']) ? (string) $_GET['code'] : '';
$error = isset($_GET['error']) ? (string) $_GET['error'] : '';
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Tesla koppelen</title>
  <style>
    :root { color-scheme: dark; }
    body { margin:0; min-height:100dvh; display:grid; place-items:center; background:#070708; color:#f4f4f5; font-family:ui-sans-serif,system-ui,sans-serif; padding:20px; }
    .card { width:min(420px,100%); background:#121214; border:1px solid #2a2a2e; border-radius:18px; padding:18px; }
    input { width:100%; min-height:46px; border-radius:12px; border:1px solid #2a2a2e; background:#0c0c0e; color:#fff; padding:0 12px; margin-top:8px; }
    button { width:100%; min-height:46px; border:0; border-radius:999px; background:#c41e3a; color:#fff; font-weight:600; margin-top:12px; }
    .muted { color:#9a9aa3; font-size:.92rem; line-height:1.45; }
  </style>
</head>
<body>
  <div class="card" id="box"></div>
  <script>
    const code = <?php echo json_encode($code, JSON_UNESCAPED_SLASHES); ?>;
    const err = <?php echo json_encode($error, JSON_UNESCAPED_SLASHES); ?>;
    const box = document.getElementById("box");
    const saved = JSON.parse(localStorage.getItem("fsd-europe-data-hosting") || "{}");
    const cid = sessionStorage.getItem("tesla_id") || saved.clientId || "5ad68096-94cd-472e-ab99-b8781cf71e71";
    const sec = sessionStorage.getItem("tesla_secret") || saved.clientSecret || "";

    function render(html) { box.innerHTML = html; }

    async function finish(clientId, clientSecret) {
      render("<p class='muted'>Tesla-tokens uitwisselen…</p>");
      const res = await fetch("/api.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action:"exchange", clientId, clientSecret, code })
      });
      const data = await res.json();
      if (!data.ok) {
        showForm(data.message || "Mislukt.");
        return;
      }
      localStorage.setItem("fsd-europe-data-hosting", JSON.stringify({
        ...saved, clientId, clientSecret, accessToken: data.accessToken,
        refreshToken: data.refreshToken, region: data.region, mode: "live"
      }));
      location.replace("/");
    }

    function showForm(msg) {
      render(`
        <p style="font-weight:600;margin:0 0 8px">Tesla heeft toestemming gegeven</p>
        <p class="muted">Plak Client ID en Secret van Berkant. Blijf op dit scherm.</p>
        <input id="cid" value="${cid}" placeholder="Client ID" autocomplete="off" />
        <input id="sec" type="password" value="" placeholder="Client Secret" autocomplete="off" />
        <button id="go">Koppeling afronden</button>
        <p class="muted" id="m">${msg || ""}</p>`);
      document.getElementById("go").onclick = () => finish(
        document.getElementById("cid").value.trim(),
        document.getElementById("sec").value.trim()
      );
    }

    if (err) render("<p class='muted'>Tesla weigerde de koppeling (" + err + ").</p>");
    else if (!code) render("<p class='muted'>Geen autorisatiecode. Open fsd-europe-data.com en start Tesla-login opnieuw.</p>");
    else if (sec) finish(cid, sec);
    else showForm("");
  </script>
</body>
</html>
