package com.vivox.iptv;

import android.app.Activity;
import android.content.Intent;
import android.os.Bundle;
import android.os.Handler;
import android.view.KeyEvent;
import android.webkit.WebView;
import android.webkit.WebSettings;
import android.webkit.WebViewClient;
import android.util.Base64;
import android.widget.Toast;

public class MainActivity extends Activity {

    private WebView webView1;
    private boolean doubleBackToExitPressedOnce = false;

    // HTML Kodu Base64 Şifreli (APK'da gizli)
    private static final String ENCRYPTED_HTML = 
        "PCFET0NUWVBFIGh0bWw+CjxodG1sIGxhbmc9InRyIiBkYXRhLXRoZW1lPSJuaWdodCI+CjxoZWFkPgogIDxtZXRhIGNoYXJzZXQ9InV0Zi04IiAvPgogIDxtZXRhIG5hbWU9InZpZXdwb3J0IiBjb250ZW50PSJ3aWR0aD1kZXZpY2Utd2lkdGgsaW5pdGlhbC1zY2FsZT0xLjAiIC8+CiAgPHRpdGxlPlZJVk8tWDwvdGl0bGU+CiAgPHN0eWxlPgogICAgKiB7IG1hcmdpbjogMDsgcGFkZGluZzogMDsgYm94LXNpemluZzogYm9yZGVyLWJveDsgfQogICAgYm9keSB7IGJhY2tncm91bmQ6ICMwOTA4MGY7IGNvbG9yOiAjZmZmOyBmb250LWZhbWlseTogJ0ludGVyJywgc2Fucy1zZXJpZjsgfQogICAgLmNvbnRhaW5lciB7IHBhZGRpbmc6IDIwcHg7IHRleHQtYWxpZ246IGNlbnRlcjsgfQogICAgLmNvbnRhaW5lciBoMSB7IGZvbnQtc2l6ZTogMjhweDsgZm9udC13ZWlnaHQ6IDkwMDsgYmFja2dyb3VuZDogbGluZWFyLWdyYWRpZW50KDkwZGVnLCBhbGlnaCwgdHJhbnNwYXJlbnQsICNmZmZmZmYpOyAtd2Via2l0LWJhY2tncm91bmQtY2xpcDogdGV4dDsgY29sb3I6IHRyYW5zcGFyZW50OyB9CiAgICAuY2FyZCB7IGRpc3BsYXk6IGlubGluZS1ibG9jazsgYmFja2dyb3VuZDogcmdiYSgyNTUsMjU1LDI1NSwwLjA1KTsgYm9yZGVyLXJhZGl1czogMTZweDsgcGFkZGluZzogMjBweDsgbWFyZ2luOiAxMHB4OyBib3JkZXI6IDFweCBzb2xpZCByZ2JhKDI1NSwyNTUsMjU1LDAuMSk7IH0KICAgIC5jYXJkIGltZyB7IHdpZHRoOiA4MHB4OyBoZWlnaHQ6IDgwcHg7IGJvcmRlci1yYWRpdXM6IDUwJTsgfQogICAgLmNhcmQgaDIgeyBmb250LXNpemU6IDE4cHg7IG1hcmdpbjogMTBweCAwIDVweDsgfQogICAgLmNhcmQgcCB7IGNvbG9yOiAjODg4OyBmb250LXNpemU6IDE0cHg7IH0KICAgIC5jYXJkIGJ1dHRvbiB7IGJhY2tncm91bmQ6ICNmZmY7IGNvbG9yOiAjMDAwOyBib3JkZXI6IG5vbmU7IHBhZGRpbmc6IDEwcHggMzBweDsgYm9yZGVyLXJhZGl1czogMjVweDsgZm9udC13ZWlnaHQ6IDcwMDsgY3Vyc29yOiBwb2ludGVyOyB9CiAgPC9zdHlsZT4KPC9oZWFkPgo8Ym9keT4KICA8ZGl2IGNsYXNzPSJjb250YWluZXIiPgogICAgPGgxPlx1MDEwMFZJVk8tWCA8L2gxPgogICAgPHAgPjxzdHlsZT1jb2xvcjojODg4PldlbGNvbWUgdG8gVklWTy1YIElQVFYgUGxheWVyPC9wPgogICAgPGRpdiBjbGFzcz0iY2FyZCI+CiAgICAgIDxpbWcgc3JjPSJodHRwczovL2kuaW1ndXIuY29tL2RlZmF1bHQucG5nIiBhbHQ9IkxvZ28iIC8+CiAgICAgIDxoMj5WxLBPWC1YPC9oMj4KICAgICAgPHA+TUlVIGxpc3RlcyBpXHUwMTk3aW4ga1x1MDExOXTEsSBiZWNrYW4gPC9wPgogICAgICA8YnV0dG9uIG9uY2xpY2s9IndpbmRvdy5sb2NhdGlvbi5ocmVmPSd0ZzovL3Jlc29sdmU/ZG9tYWluPVZpdm9fWF9NZWR5YSc7Ij5UZWxlZ3JhbSBLYW5hbFx1MDExMSA8L2J1dHRvbj4KICAgIDwvZGl2PgogIDwvZGl2Pgo8L2JvZHk+CjwvaHRtbD4=";

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        // Tam ekran yap
        getWindow().getDecorView().setSystemUiVisibility(
            View.SYSTEM_UI_FLAG_LAYOUT_STABLE |
            View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION |
            View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN |
            View.SYSTEM_UI_FLAG_HIDE_NAVIGATION |
            View.SYSTEM_UI_FLAG_FULLSCREEN |
            View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
        );

        webView1 = findViewById(R.id.webView1);
        WebSettings settings = webView1.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setLoadWithOverviewMode(true);
        settings.setUseWideViewPort(true);
        settings.setBuiltInZoomControls(false);
        settings.setDisplayZoomControls(false);
        settings.setSupportZoom(false);
        settings.setCacheMode(WebSettings.LOAD_NO_CACHE);
        settings.setAllowFileAccess(false);
        settings.setAllowContentAccess(false);

        // WebViewClient ile link tıklamalarını yakala
        webView1.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, String url) {
                // Eğer URL video linkiyse PlayerActivity'ye yönlendir
                if (url.startsWith("http") && (url.contains(".m3u8") || url.contains(".ts") || 
                    url.contains("playlist") || url.contains("live") || url.contains("stream"))) {
                    Intent intent = new Intent(MainActivity.this, PlayerActivity.class);
                    intent.putExtra("video_url", url);
                    startActivity(intent);
                    return true;
                }
                // Telegram linki
                if (url.startsWith("tg://")) {
                    try {
                        Intent tgIntent = new Intent(Intent.ACTION_VIEW, Uri.parse(url));
                        startActivity(tgIntent);
                    } catch (Exception e) {
                        Toast.makeText(MainActivity.this, "Telegram yüklü değil", Toast.LENGTH_SHORT).show();
                    }
                    return true;
                }
                return false;
            }
        });

        // HTML'i çöz ve yükle
        String html = new String(Base64.decode(ENCRYPTED_HTML, Base64.DEFAULT));
        webView1.loadDataWithBaseURL(null, html, "text/html", "UTF-8", null);
    }

    @Override
    public boolean onKeyDown(int keyCode, KeyEvent event) {
        if (keyCode == KeyEvent.KEYCODE_BACK) {
            if (webView1.canGoBack()) {
                webView1.goBack();
                return true;
            } else {
                // 2 kez geri tuşu ile çıkış
                if (doubleBackToExitPressedOnce) {
                    super.onKeyDown(keyCode, event);
                    finishAffinity();
                    return true;
                }
                this.doubleBackToExitPressedOnce = true;
                Toast.makeText(this, "Çıkmak için tekrar geri tuşuna basın", Toast.LENGTH_SHORT).show();

                new Handler().postDelayed(new Runnable() {
                    @Override
                    public void run() {
                        doubleBackToExitPressedOnce = false;
                    }
                }, 2000);
                return true;
            }
        }
        return super.onKeyDown(keyCode, event);
    }

    @Override
    public void onWindowFocusChanged(boolean hasFocus) {
        super.onWindowFocusChanged(hasFocus);
        if (hasFocus) {
            getWindow().getDecorView().setSystemUiVisibility(
                View.SYSTEM_UI_FLAG_LAYOUT_STABLE |
                View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION |
                View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN |
                View.SYSTEM_UI_FLAG_HIDE_NAVIGATION |
                View.SYSTEM_UI_FLAG_FULLSCREEN |
                View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
            );
        }
    }
}
