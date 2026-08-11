package com.conjure.runtime;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Build;
import java.io.File;

public class BootReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        if (intent == null || intent.getAction() == null) return;

        String action = intent.getAction();
        if (Intent.ACTION_BOOT_COMPLETED.equals(action) ||
            Intent.ACTION_MY_PACKAGE_REPLACED.equals(action) ||
            "android.intent.action.QUICKBOOT_POWERON".equals(action) ||
            "com.htc.intent.action.QUICKBOOT_POWERON".equals(action)) {

            SharedPreferences prefs = context.getSharedPreferences("ConjureRuntimeState", Context.MODE_PRIVATE);
            boolean autoStartBoot = prefs.getBoolean("auto_start_boot", false);

            if (autoStartBoot) {
                File conjureRoot = new File("/storage/emulated/0/Conjure OS");
                File indexFile = new File(conjureRoot, "index.php");
                if (indexFile.isFile() && indexFile.length() > 0) {
                    Intent serviceIntent = new Intent(context, RuntimeService.class);
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                        context.startForegroundService(serviceIntent);
                    } else {
                        context.startService(serviceIntent);
                    }
                }
            }
        }
    }
}