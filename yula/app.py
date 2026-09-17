# app.py — Giniko API tabanlı M3U proxy
# Ana endpoint: https://ginikoturkish.com/api/droid/service.php?operation=getChannels
# Tek istekte tüm kanal listesi gelir → çok hızlı

from flask import Flask, Response, request, jsonify
from flask_cors import CORS
import requests
import json
import time

app = Flask(__name__)
CORS(app)

API_URL = "https://ginikoturkish.com/api/droid/service.php?operation=getChannels"
BASE_URL = "https://ginikoturkish.com"

HEADERS = {
    "User-Agent": "Mozilla/5.0",
    "Referer": "https://www.ginikoturkish.com/",
    "Accept": "application/json, */*"
}

CACHE = {"channels": [], "ts": 0, "raw": None}
CACHE_TTL = 1800


# ============ TÜM KANALLARI API'DEN ÇEK ============
def fetch_all_channels(force=False):
    now = time.time()
    if not force and CACHE["channels"] and (now - CACHE["ts"]) < CACHE_TTL:
        return CACHE["channels"]

    try:
        r = requests.get(API_URL, headers=HEADERS, timeout=15)
        if r.status_code != 200:
            print(f"API hata: {r.status_code}")
            return CACHE["channels"] or []

        # JSON mu, HTML mi?
        text = r.text.strip()
        CACHE["raw"] = text[:5000]  # debug için sakla

        data = None
        try:
            data = r.json()
        except Exception:
            # JSON değilse HTML/XML olabilir
            print("JSON parse hatası, ham veri:")
            print(text[:500])
            return CACHE["channels"] or []

        # Farklı JSON formatlarını dene
        channels = []

        # Format 1: {"channels": [...]}
        if isinstance(data, dict):
            if "channels" in data and isinstance(data["channels"], list):
                channels = data["channels"]
            elif "data" in data and isinstance(data["data"], list):
                channels = data["data"]
            elif "result" in data and isinstance(data["result"], list):
                channels = data["result"]
            else:
                # dict içindeki ilk listeyi bul
                for v in data.values():
                    if isinstance(v, list) and v and isinstance(v[0], dict):
                        channels = v
                        break

        # Format 2: direkt liste
        elif isinstance(data, list):
            channels = data

        # Alan isimlerini normalize et
        normalized = []
        for ch in channels:
            if not isinstance(ch, dict):
                continue

            # Farklı olası alan adları
            cid = ch.get("id") or ch.get("channel_id") or ch.get("channelId") or ch.get("ch")
            name = ch.get("name") or ch.get("channel_name") or ch.get("channelName") or ch.get("title")
            logo = ch.get("logo") or ch.get("logoUrl") or ch.get("logoUrlHD") or ch.get("icon") or ch.get("image")
            stream = ch.get("stream") or ch.get("stream_url") or ch.get("streamUrl") or ch.get("url") or ch.get("hls") or ch.get("HlsStreamURL")
            is_vod = ch.get("isVOD") or ch.get("is_vod") or ch.get("isVod")

            if not cid or not stream:
                continue

            # VOD değilse
            if is_vod is not None:
                if str(is_vod).lower() in ("true", "1"):
                    continue

            if not name:
                name = f"Kanal {cid}"

            normalized.append({
                "id": int(cid) if str(cid).isdigit() else cid,
                "name": str(name).replace(" - Live", "").strip(),
                "logo": logo or f"https://www.giniko.com/logos/190x110/{cid}.jpg",
                "stream": stream,
                "isVOD": is_vod
            })

        normalized.sort(key=lambda x: (isinstance(x["id"], str), x["id"]))
        CACHE["channels"] = normalized
        CACHE["ts"] = now
        print(f"[API] {len(normalized)} kanal alındı")
        return normalized

    except Exception as e:
        print(f"API fetch hatası: {e}")
        return CACHE["channels"] or []


# ============ TEK KANAL ============
def get_channel(cid):
    all_ch = fetch_all_channels()
    for ch in all_ch:
        if str(ch["id"]) == str(cid):
            return ch
    return None


# ============ M3U ============
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
        .replace("ı", "i").replace("ş", "s").replace("ğ", "g")\
        .replace("ü", "u").replace("ö", "o").replace("ç", "c")\
        .replace(" ", "")


# ============ ENDPOINT'LER ============
@app.route("/")
def home():
    ch = fetch_all_channels()
    return jsonify({
        "status": "ok",
        "total": len(ch),
        "api": API_URL,
        "endpoints": {
            "/index.m3u": "tüm kanallar",
            "/1.m3u": "ID 1",
            "/trt.m3u": "isimle",
            "/channel/1": "JSON",
            "/list": "JSON liste",
            "/raw": "API ham yanıt (debug)",
            "/refresh": "cache temizle ve yenile"
        }
    })


@app.route("/raw")
def raw():
    fetch_all_channels(force=True)
    return Response(CACHE["raw"] or "bos", mimetype="text/plain")


@app.route("/refresh")
def refresh():
    CACHE["channels"] = []
    CACHE["ts"] = 0
    ch = fetch_all_channels(force=True)
    return jsonify({"status": "ok", "total": len(ch)})


@app.route("/channel/<cid>")
def channel(cid):
    ch = get_channel(cid)
    if not ch:
        return jsonify({"hata": "bulunamadi", "id": cid}), 404
    return jsonify(ch)


@app.route("/list")
def list_all():
    force = request.args.get("refresh") == "true"
    ch = fetch_all_channels(force)
    return jsonify({"total": len(ch), "channels": ch})


@app.route("/index.m3u")
@app.route("/m3u")
@app.route("/<path:q>.m3u")
def m3u(q=None):
    host = request.host_url.rstrip("/")
    ch_param = request.args.get("ch") or request.args.get("kanal") or q
    force = request.args.get("refresh") == "true"
    all_ch = fetch_all_channels(force)

    if ch_param and ch_param != "index":
        if ch_param.isdigit():
            ch = next((c for c in all_ch if str(c["id"]) == ch_param), None)
            if not ch:
                return Response(
                    f"#EXTM3U\n# ID {ch_param} bulunamadi\n",
                    status=404, mimetype="audio/x-mpegurl"
                )
            return Response(build_m3u([ch], host), mimetype="audio/x-mpegurl")

        n = norm(ch_param)
        matches = [c for c in all_ch if n in norm(c["name"])]
        if not matches:
            return Response(
                f"#EXTM3U\n# {ch_param} bulunamadi\n",
                status=404, mimetype="audio/x-mpegurl"
            )
        return Response(build_m3u(matches, host), mimetype="audio/x-mpegurl")

    return Response(build_m3u(all_ch, host), mimetype="audio/x-mpegurl")


@app.route("/proxy")
def proxy():
    u = request.args.get("url")
    if not u:
        return "missing url", 400
    try:
        r = requests.get(u, headers=HEADERS, stream=True, timeout=20, allow_redirects=True)
        ct = r.headers.get("content-type", "")

        if "mpegurl" in ct or u.split("?")[0].endswith(".m3u8"):
            text = r.text
            base = "/".join(u.split("/")[:-1]) + "/"
            host = request.host_url.rstrip("/")
            import re as _re
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
                        t = _re.sub(r'URI="([^"]+)"', repl, t)
                    out_lines.append(t)
                    continue
                if not t.startswith("http"):
                    t = base + t
                out_lines.append(f'{host}/proxy?url={requests.utils.quote(t, safe="")}')
            return Response(
                "\n".join(out_lines),
                mimetype="application/vnd.apple.mpegurl",
                headers={"Access-Control-Allow-Origin": "*"}
            )

        def gen():
            for chunk in r.iter_content(16384):
                yield chunk
        return Response(
            gen(), status=r.status_code, content_type=ct,
            headers={"Access-Control-Allow-Origin": "*"}
        )
    except Exception as e:
        return f"proxy error: {e}", 502


if __name__ == "__main__":
    print("=" * 60)
    print("Giniko API M3U Proxy")
    print(f"API: {API_URL}")
    print("=" * 60)
    print("İlk kanal listesi çekiliyor...")
    fetch_all_channels(force=True)
    print(f"Toplam {len(CACHE['channels'])} kanal hazır")
    print("=" * 60)
    print("Test:")
    print("  http://127.0.0.1:8080/")
    print("  http://127.0.0.1:8080/raw       <- API ham yanıt")
    print("  http://127.0.0.1:8080/list      <- JSON")
    print("  http://127.0.0.1:8080/index.m3u <- M3U")
    print("=" * 60)
    app.run(host="0.0.0.0", port=8080, threaded=True)
