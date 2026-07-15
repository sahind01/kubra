package com.vivox.iptv;

import android.app.Activity;
import android.content.pm.ActivityInfo;
import android.media.MediaPlayer;
import android.net.Uri;
import android.os.Bundle;
import android.os.Handler;
import android.view.View;
import android.view.WindowManager;
import android.widget.MediaController;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.VideoView;
import android.widget.Toast;
import android.view.KeyEvent;

public class PlayerActivity extends Activity {

    private VideoView videoView;
    private ProgressBar progressBar;
    private TextView errorText;
    private String videoUrl;
    private MediaController mediaController;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        
        // Tam ekran yatay
        setRequestedOrientation(ActivityInfo.SCREEN_ORIENTATION_LANDSCAPE);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_FULLSCREEN);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_LAYOUT_NO_LIMITS);

        setContentView(R.layout.activity_player);

        // System UI gizle (status bar, navigation bar)
        hideSystemUI();

        videoView = findViewById(R.id.videoView);
        progressBar = findViewById(R.id.progressBar);
        errorText = findViewById(R.id.errorText);

        videoUrl = getIntent().getStringExtra("video_url");

        if (videoUrl == null || videoUrl.isEmpty()) {
            errorText.setText("Video URL bulunamadı");
            errorText.setVisibility(View.VISIBLE);
            return;
        }

        // MediaController (player kontrolleri)
        mediaController = new MediaController(this) {
            @Override
            public void hide() {
                // Kontrolleri gizleme (her zaman göster)
                // super.hide() yorum satırı yapıldı
            }
        };
        mediaController.setAnchorView(videoView);

        // Video'yu yükle
        playVideo();
    }

    private void playVideo() {
        progressBar.setVisibility(View.VISIBLE);
        errorText.setVisibility(View.GONE);

        try {
            Uri videoUri = Uri.parse(videoUrl);
            videoView.setVideoURI(videoUri);
            videoView.setMediaController(mediaController);

            videoView.setOnPreparedListener(new MediaPlayer.OnPreparedListener() {
                @Override
                public void onPrepared(MediaPlayer mp) {
                    progressBar.setVisibility(View.GONE);
                    mp.setLooping(true);
                    videoView.start();
                    // Ses seviyesini max yap
                    mp.setVolume(1.0f, 1.0f);
                }
            });

            videoView.setOnErrorListener(new MediaPlayer.OnErrorListener() {
                @Override
                public boolean onError(MediaPlayer mp, int what, int extra) {
                    progressBar.setVisibility(View.GONE);
                    errorText.setText("Video oynatılamadı: " + what);
                    errorText.setVisibility(View.VISIBLE);
                    return true;
                }
            });

            videoView.setOnCompletionListener(new MediaPlayer.OnCompletionListener() {
                @Override
                public void onCompletion(MediaPlayer mp) {
                    // Bittiğinde tekrar başlat
                    videoView.start();
                }
            });

        } catch (Exception e) {
            progressBar.setVisibility(View.GONE);
            errorText.setText("Hata: " + e.getMessage());
            errorText.setVisibility(View.VISIBLE);
        }
    }

    private void hideSystemUI() {
        getWindow().getDecorView().setSystemUiVisibility(
            View.SYSTEM_UI_FLAG_LAYOUT_STABLE |
            View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION |
            View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN |
            View.SYSTEM_UI_FLAG_HIDE_NAVIGATION |
            View.SYSTEM_UI_FLAG_FULLSCREEN |
            View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
        );
    }

    @Override
    public void onWindowFocusChanged(boolean hasFocus) {
        super.onWindowFocusChanged(hasFocus);
        if (hasFocus) {
            hideSystemUI();
        }
    }

    @Override
    public boolean onKeyDown(int keyCode, KeyEvent event) {
        if (keyCode == KeyEvent.KEYCODE_BACK) {
            finish();
            return true;
        }
        return super.onKeyDown(keyCode, event);
    }

    @Override
    protected void onPause() {
        super.onPause();
        if (videoView != null && videoView.isPlaying()) {
            videoView.pause();
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        hideSystemUI();
        if (videoView != null && !videoView.isPlaying()) {
            videoView.start();
        }
    }

    @Override
    protected void onDestroy() {
        super.onDestroy();
        if (videoView != null) {
            videoView.stopPlayback();
        }
    }
}
