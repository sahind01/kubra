# app.py
from flask import Flask, Response, request, jsonify
from flask_cors import CORS
import requests, json, re, time

app = Flask(__name__)
CORS(app)

API_URL = "https://ginikoturkish.com/api/droid/service.php?operation=getChannels"
HEADERS = {
    "User-Agent": "Mozilla/5.0",
    "Referer": "https://www.ginikoturkish.com/"
}

CACHE = {"ch": [], "ts": 0}
TTL = 1800


def fetch_channels(force=False):
    if not force and CACHE["ch"] and time.time() - CACHE["ts"] < TTL:
        return CACHE["ch"]

    r = requests.get(API_URL, headers=HEADERS, timeout=20)
    data = r.json()

    # Farklı formatları dene
    raw = []
    if isinstance(data, list):
        raw = data
    elif isinstance(data, dict):
        for k in ("channels", "data", "result", "list"):
            if isinstance(data.get(k), list):
                raw = data[k]; break
        if not raw:
            for v in data.values():
                if isinstance(v, list) and v and isinstance(v[0], dict):
                    raw = v; break

    out = []
    for c in raw:
        cid = c.get("id") or c.get("channel_id") or c.get("channelId") or c.get("ch")
        name = c.get("name") or c.get("channel_name") or c.get("channelName") or c.get("title")
        logo = c.get("logo") or c.get("logoUrl") or c.get("logoUrlHD") or c.get("icon")
        stream = (c.get("stream") or c.get("stream_url") or c.get("streamUrl")
                  or c.get("url") or c.get("hls") or c.get("HlsStreamURL"))
        vod = c.get("isVOD") or c.get("is_vod")

        if not cid or not stream: continue
        if vod is not None and str(vod).lower() in ("true", "1"): continue

        out.append({
            "id": cid,
            "name": (name or f"Kanal {cid}").replace(" - Live", "").strip(),
            "logo": logo or f"https://www.giniko.com/logos/190x110/{cid}.jpg",
            "stream": stream
        })

    CACHE["ch"] = out
    CACHE["ts"] = time.time()
    return out


def build_m3u(channels, host):
    out = "#EXTM3U\n"
    for c in channels:
        s = f"{host}/proxy?url=" + requests.utils.quote(c["stream"], safe="")
        l = f"{host}/proxy?url=" + requests.utils.quote(c["logo"], safe="")
        out += f'#EXTINF:-1 tvg-id="{c["id"]}" tvg-name="{c["name"]}" tvg-logo="{l}" group-title="TR",{c["name"]}\n'
        out += f"{s}\n"
    return out


def norm(s):
    return (s or "").lower()\
        .replace("ı","i").replace("ş","s").replace("ğ","g")\
        .replace("ü","u").replace("ö","o").replace("ç","c")\
        .replace(" ","")


@app.route("/")
def home():
    ch = fetch_channels()
    return jsonify({"status": "ok", "total": len(ch)})


@app.route("/raw")
def raw():
    r = requests.get(API_URL, headers=HEADERS, timeout=20)
    return Response(r.text[:5000], mimetype="text/plain")


@app.route("/channel/<cid>")
def channel(cid):
    for c in fetch_channels():
        if str(c["id"]) == str(cid):
            return jsonify(c)
    return jsonify({"hata": "bulunamadi", "id": cid}), 404


@app.route("/list")
def list_all():
    ch = fetch_channels(request.args.get("refresh") == "true")
    return jsonify({"total": len(ch), "channels": ch})


@app.route("/index.m3u")
@app.route("/m3u")
@app.route("/<path:q>.m3u")
def m3u(q=None):
    host = request.host_url.rstrip("/")
    p = request.args.get("ch") or request.args.get("kanal") or q
    all_ch = fetch_channels(request.args.get("refresh") == "true")

    if p and p != "index":
        if p.isdigit():
            ch = next((c for c in all_ch if str(c["id"]) == p), None)
            if not ch:
                return Response(f"#EXTM3U\n# {p} yok\n", 404, mimetype="audio/x-mpegurl")
            return Response(build_m3u([ch], host), mimetype="audio/x-mpegurl")
        n = norm(p)
        m = [c for c in all_ch if n in norm(c["name"])]
        if not m:
            return Response(f"#EXTM3U\n# {p} yok\n", 404, mimetype="audio/x-mpegurl")
        return Response(build_m3u(m, host), mimetype="audio/x-mpegurl")

    return Response(build_m3u(all_ch, host), mimetype="audio/x-mpegurl")


@app.route("/proxy")
def proxy():
    u = request.args.get("url")
    if not u: return "missing url", 400
    r = requests.get(u, headers=HEADERS, stream=True, timeout=20, allow_redirects=True)
    ct = r.headers.get("content-type", "")

    if "mpegurl" in ct or u.split("?")[0].endswith(".m3u8"):
        text = r.text
        base = "/".join(u.split("/")[:-1]) + "/"
        host = request.host_url.rstrip("/")
        lines = []
        for line in text.split("\n"):
            t = line.strip()
            if not t:
                lines.append(line); continue
            if t.startswith("#"):
                if 'URI="' in t:
                    t = re.sub(r'URI="([^"]+)"',
                               lambda m: f'URI="{host}/proxy?url={requests.utils.quote((m.group(1) if m.group(1).startswith("http") else base+m.group(1)), safe="")}"',
                               t)
                lines.append(t); continue
            if not t.startswith("http"): t = base + t
            lines.append(f'{host}/proxy?url={requests.utils.quote(t, safe="")}')
        return Response("\n".join(lines),
                        mimetype="application/vnd.apple.mpegurl",
                        headers={"Access-Control-Allow-Origin": "*"})

    return Response((c for c in r.iter_content(16384)),
                    status=r.status_code, content_type=ct,
                    headers={"Access-Control-Allow-Origin": "*"})


if __name__ == "__main__":
    print("İlk liste çekiliyor...")
    ch = fetch_channels(force=True)
    print(f"{len(ch)} kanal hazır")
    app.run(host="0.0.0.0", port=8080, threaded=True)
