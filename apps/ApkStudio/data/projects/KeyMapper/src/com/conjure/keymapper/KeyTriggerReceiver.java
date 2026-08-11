package com.conjure.keymapper;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

public class KeyTriggerReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        if (intent == null) return;

        String action = intent.getAction();
        if ("com.conjure.keymapper.TRIGGER".equalsIgnoreCase(action)) {
            String rule = intent.getStringExtra("rule");
            if (rule == null || rule.isEmpty()) {
                rule = intent.getStringExtra("alias");
            }
            if (rule == null || rule.isEmpty()) {
                rule = intent.getStringExtra("id");
            }
            if (rule == null || rule.isEmpty()) {
                rule = intent.getStringExtra("slug");
            }

            if (rule != null && !rule.trim().isEmpty()) {
                MainActivity.executeDeepLinkRule(context, rule.trim());
            }
        }
    }
}