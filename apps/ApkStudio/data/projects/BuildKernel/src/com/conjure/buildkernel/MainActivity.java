package com.conjure.buildkernel;

import android.app.Activity;
import android.content.Intent;
import android.os.Bundle;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Button;
import android.view.Gravity;
import android.graphics.Color;
import java.io.*;

public class MainActivity extends Activity {
    private TextView statusText;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        
        final LinearLayout layout = new LinearLayout(this);
        layout.setOrientation(LinearLayout.VERTICAL);
        layout.setGravity(Gravity.CENTER);
        layout.setPadding(32, 32, 32, 32);
        layout.setBackgroundColor(Color.parseColor("#121214"));

        TextView titleText = new TextView(this);
        titleText.setText("Conjure BuildKernel");
        titleText.setTextSize(24);
        titleText.setTextColor(Color.WHITE);
        titleText.setPadding(0, 0, 0, 32);
        layout.addView(titleText);

        statusText = new TextView(this);
        statusText.setText("Status: Initializing...");
        statusText.setTextSize(16);
        statusText.setTextColor(Color.LTGRAY);
        statusText.setPadding(0, 0, 0, 64);
        layout.addView(statusText);

        final Button toggleButton = new Button(this);
        final boolean isRunning = BuildService.isRunning;
        toggleButton.setText(isRunning ? "Stop Daemon" : "Start Daemon");
        toggleButton.setEnabled(false); // Disabled until toolchain extraction completes

        toggleButton.setOnClickListener(new android.view.View.OnClickListener() {
            @Override
            public void onClick(android.view.View v) {
                boolean active = BuildService.isRunning;
                Intent intent = new Intent(MainActivity.this, BuildService.class);
                if (active) {
                    stopService(intent);
                    toggleButton.setText("Start Daemon");
                    statusText.setText("Status: Daemon offline");
                    statusText.setTextColor(Color.LTGRAY);
                } else {
                    if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.O) {
                        startForegroundService(intent);
                    } else {
                        startService(intent);
                    }
                    toggleButton.setText("Stop Daemon");
                    statusText.setText("Status: Daemon active on port 8089");
                    statusText.setTextColor(Color.GREEN);
                }
            }
        });

        layout.addView(toggleButton);
        setContentView(layout);

        initToolchain(toggleButton);
    }

    @Override
    protected void onResume() {
        super.onResume();
        // Use Java reflection to access Android 11+ (API 30+) storage APIs.
        // This ensures compiled compatibility against legacy android.jar files in Termux (which target API < 30)
        // while safely executing on modern Android 11/12/13/14+ devices.
        if (android.os.Build.VERSION.SDK_INT >= 30) {
            try {
                Class<?> envClass = Class.forName("android.os.Environment");
                java.lang.reflect.Method isManagerMethod = envClass.getMethod("isExternalStorageManager");
                boolean isManager = (Boolean) isManagerMethod.invoke(null);
                
                if (!isManager) {
                    android.content.Intent intent = new android.content.Intent(
                        "android.settings.MANAGE_APP_ALL_FILES_ACCESS_PERMISSION",
                        android.net.Uri.parse("package:" + getPackageName())
                    );
                    startActivity(intent);
                }
            } catch (Exception e) {
                e.printStackTrace();
            }
        }
    }

    private void initToolchain(final Button toggleButton) {
        final File toolchainDir = new File(getFilesDir(), "toolchain");
        if (!toolchainDir.exists()) {
            toolchainDir.mkdirs();
        }

        new Thread(new Runnable() {
            @Override
            public void run() {
                try {
                    String[] expected = {"android.jar", "ecj.jar", "d8.jar", "apksigner.jar", "debug.keystore"};
                    
                    for (final String name : expected) {
                        File dest = new File(toolchainDir, name);
                        if (!dest.exists()) {
                            runOnUiThread(new Runnable() {
                                @Override
                                public void run() {
                                    statusText.setText("Extracting toolchain: " + name + "...");
                                    statusText.setTextColor(Color.YELLOW);
                                }
                            });
                            
                            try (InputStream in = getAssets().open(name);
                                 OutputStream out = new FileOutputStream(dest)) {
                                byte[] buf = new byte[8192];
                                int len;
                                while ((len = in.read(buf)) > 0) {
                                    out.write(buf, 0, len);
                                }
                            }
                        }
                        // Android 14+ strictly mandates that dynamically loaded DEX/JAR files are read-only (r--------).
                        // If any file in the classpath is writable, dalvikvm aborts with SIGABRT / Exit 134 on startup.
                        dest.setWritable(false, false);
                    }

                    runOnUiThread(new Runnable() {
                        @Override
                        public void run() {
                            toggleButton.setEnabled(true);
                            boolean isRunning = BuildService.isRunning;
                            statusText.setText(isRunning ? "Status: Daemon active on port 8089" : "Status: Daemon offline");
                            statusText.setTextColor(isRunning ? Color.GREEN : Color.LTGRAY);
                        }
                    });

                } catch (final Exception e) {
                    runOnUiThread(new Runnable() {
                        @Override
                        public void run() {
                            statusText.setText("Toolchain error: " + e.getMessage());
                            statusText.setTextColor(Color.RED);
                        }
                    });
                }
            }
        }).start();
    }


}