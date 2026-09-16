# app.py
from flask import Flask, Response, request, jsonify
from flask_cors import CORS
import requests, re, time
from concurrent.futures import ThreadPoolExecutor

app = Flask(__name__)
CORS(app)

TOTAL = 1000
WORKERS = 30
ALLOWED_DOMAIN = "trn03.tulix.tv"
BASE_URL = "https://ginikoturkish.com"

HEADERS = {
    "User-Agent": "Mozilla/5.0",
    "Referer": "https://www.ginikoturkish.com/"
}

CACHE = {"channels": [], "ts": 0}
CACHE_TTL = 1800  # 30 dk


def check_channel(ch_id):
    url = f"{BASE_URL}/xml/secure/plist.php?ch={ch_id}"
    try:
        r = requests.get(url, headers=HEADERS, timeout=8)
        if r.status_code != 200 or "HlsStreamURL" not in r.text:
            return None
        text = r.text
        stream_url = None
        lines = [l.strip() for l in text.split("\n") if l.strip()]
        last_isvod = None
        for i, line in enumerate(lines):
            if line == "isVOD" and i+1 < len(lines):
                last_isvod = lines[i+1]
            if line == "HlsStreamURL" and i+1 < len(lines):
                u = lines[i+1]
                if u.startswith("http") and last_isvod == "false":
                    stream_url = u
                    break
        if not stream_url:
            m = re.search(r'<key>isVOD</key>\s*<string>false</string>.*?<key>HlsStreamURL</key>\s*<string>(.*?)</string>', text, re.DOTALL)
            if m:
                stream_url = m.group(1)
        if not stream_url:
            return None
        if ALLOWED_DOMAIN not in stream_url:
            return None
        name = None
        for i, line in enumerate(lines):
            if line == "name" and i+1 < len(lines) and not lines[i+1].startswith("http"):
                name = lines[i+1].replace(" - Live", "").strip()
                break
        if not name:
            m = re.search(r'<key>name</key>\s*<string>(.*?)</string>', text)
            name = m.group(1).replace(" - Live", "").strip() if m else f"Kanal {ch_id}"
        logo = None
        for i, line in enumerate(lines):
            if line == "logoUrlHD" and i+1 < len(lines) and lines[i+1].startswith("http"):
                logo = lines[i+1]
                break
        if not logo:
            m = re.search(r'<key>logoUrlHD</key>\s*<string>(.*?)</string>', text)
            logo = m.group(1) if m else f"https://www.giniko.com/logos/190x110/{ch_id}.jpg"
        return {"id": ch_id, "name": name, "logo": logo, "stream": stream_url}
    except Exception:
        return None


def get_all(force=False):
    if not force and CACHE["channels"] and (time.time() - CACHE["ts"]) < CACHE_TTL:
        return CACHE["channels"]
    with ThreadPoolExecutor(max_workers=WORKERS) as ex:
        results = [c for c in ex.map(check_channel, range(1, TOTAL+1)) if c]
    results.sort(key=lambda x: x["id"])
    CACHE["channels"] = results
    CACHE["ts"] = time.time()
    return results


def build_m3u(channels, host):
    out = "#EXTM3U\n"
    out += f"#TOTAL:{len(channels)}\n\n"
    for ch in channels:
        s = f"{host}/proxy?url=" + requests.utils.quote(ch["stream"], safe="")
        l = f"{host}/proxy?url=" + requests.utils.quote(ch["logo"], safe="")
        out += f'#EXTINF:-1 tvg-id="{ch["id"]}" tvg-name="{ch["name"]}" tvg-logo="{l}" group-title="TR",{ch["name"]}\n'
        out += "#EXTVLCOPT:http-referrer=https://www.ginikoturkish.com/\n"
        out += "#EXTVLCOPT:http-user-agent=Mozilla/5.0\n"
        out += f"{s}\n\n"
    return out


def norm(s):
    return (s or "").lower().strip()\
        .replace("ı","i").replace("ş","s").replace("ğ","g")\
        .replace("ü","u").replace("ö","o").replace("ç","c")\
        .replace(" ","")


@app.route("/")
def home():
    return jsonify({
        "status": "ok",
        "endpoints": {
            "/index.m3u": "tüm kanallar",
            "/1.m3u": "ID 1",
            "/trtbelgesel.m3u": "isimle",
            "/channel/1": "JSON",
            "/list": "JSON liste"
        }
    })


@app.route("/channel/<int:cid>")
def channel(cid):
    ch = check_channel(cid)
    if not ch:
        return jsonify({"hata": "bulunamadı", "id": cid}), 404
    return jsonify(ch)


@app.route("/list")
def list_all():
    force = request.args.get("refresh") == "true"
    ch = get_all(force)
    return jsonify({"total": len(ch), "channels": ch})


@app.route("/index.m3u")
@app.route("/m3u")
@app.route("/<path:q>.m3u")
def m3u(q=None):
    host = request.host_url.rstrip("/")
    ch_param = request.args.get("ch") or request.args.get("kanal") or q
    force = request.args.get("refresh") == "true"

    if ch_param and ch_param != "index":
        if ch_param.isdigit():
            ch = check_channel(int(ch_param))
            if not ch:
                return Response(f"#EXTM3U\n# ID {ch_param} bulunamadi\n", status=404, mimetype="audio/x-mpegurl")
            return Response(build_m3u([ch], host), mimetype="audio/x-mpegurl")
        all_ch = get_all(force)
        n = norm(ch_param)
        matches = [c for c in all_ch if n in norm(c["name"]) or str(c["id"]) == ch_param]
        if not matches:
            return Response(f"#EXTM3U\n# {ch_param} bulunamadi\n", status=404, mimetype="audio/x-mpegurl")
        return Response(build_m3u(matches, host), mimetype="audio/x-mpegurl")

    return Response(build_m3u(get_all(force), host), mimetype="audio/x-mpegurl")


@app.route("/proxy")
def proxy():
    u = request.args.get("url")
    if not u:
        return "missing url", 400
    try:
        r = requests.get(u, headers=HEADERS, stream=True, timeout=20, allow_redirects=True)
        ct = r.headers.get("content-type", "")

        # m3u8 ise içeriği işle, segmentleri de proxy'le
        if "mpegurl" in ct or u.split("?")[0].endswith(".m3u8"):
            text = r.text
            base = "/".join(u.split("/")[:-1]) + "/"
            host = request.host_url.rstrip("/")
            out_lines = []
            for line in text.split("\n"):
                t = line.strip()
                if not t:
                    out_lines.append(line)
                    continue
                if t.startswith("#"):
                    if 'URI="' in t:
                        def repl(m):
                            uri = m.group(1)
                            if not uri.startswith("http"):
                                uri = base + uri
                            return f'URI="{host}/proxy?url={requests.utils.quote(uri, safe="")}"'
                        t = re.sub(r'URI="([^"]+)"', repl, t)
                    out_lines.append(t)
                    continue
                if not t.startswith("http"):
                    t = base + t
                out_lines.append(f'{host}/proxy?url={requests.utils.quote(t, safe="")}')
            return Response("\n".join(out_lines),
                          mimetype="application/vnd.apple.mpegurl",
                          headers={"Access-Control-Allow-Origin": "*"})

        # Diğer içerik (ts, jpg) — direkt stream
        def gen():
            for chunk in r.iter_content(16384):
                yield chunk
        return Response(gen(), status=r.status_code, content_type=ct,
                       headers={"Access-Control-Allow-Origin": "*"})
    except Exception as e:
        return f"proxy error: {e}", 502


if __name__ == "__main__":
    print("=" * 60)
    print("Giniko Turkish M3U Proxy başlatılıyor...")
    print("=" * 60)
    print("Test için:")
    print("  http://127.0.0.1:8080/index.m3u")
    print("  http://127.0.0.1:8080/1.m3u")
    print("  http://127.0.0.1:8080/list")
    print("=" * 60)
    app.run(host="0.0.0.0", port=8080, threaded=True)
