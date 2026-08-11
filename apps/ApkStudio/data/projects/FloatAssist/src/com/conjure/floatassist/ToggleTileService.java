package com.conjure.floatassist;

import android.content.Intent;
import android.os.Build;
import android.service.quicksettings.Tile;
import android.service.quicksettings.TileService;

public class ToggleTileService extends TileService {
    
    @Override
    public void onStartListening() {
        super.onStartListening();
        updateTile();
    }

    @Override
    public void onClick() {
        super.onClick();
        Intent intent = new Intent(this, FloatService.class);
        if (FloatService.isRunning) {
            stopService(intent);
        } else {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                startForegroundService(intent);
            } else {
                startService(intent);
            }
        }
        
        // Allow a brief moment for the service state to flip before updating the UI
        new android.os.Handler(android.os.Looper.getMainLooper()).postDelayed(new Runnable() {
            @Override
            public void run() {
                updateTile();
                // Broadcast to update the main app dashboard if it happens to be open
                Intent updateIntent = new Intent("com.conjure.floatassist.UPDATE_SETTINGS");
                updateIntent.setPackage(getPackageName());
                sendBroadcast(updateIntent);
            }
        }, 150);
    }

    private void updateTile() {
        Tile tile = getQsTile();
        if (tile != null) {
            tile.setLabel("FloatAssist");
            if (FloatService.isRunning) {
                tile.setState(Tile.STATE_ACTIVE);
            } else {
                tile.setState(Tile.STATE_INACTIVE);
            }
            tile.updateTile();
        }
    }
}