# Automatically trigger the native package installer overlay to prompt installation (falls back to file manager if needed)
if ! termux-open "/sdcard/Download/${APP_NAME}-debug.apk" &>/dev/null; then
    am start -a android.intent.action.VIEW -d "file:///sdcard/Download" -t "resource/folder" &>/dev/null
fi

echo "=================================================="
echo "✨ Build Complete: /sdcard/Download/${APP_NAME}-debug.apk"
echo "=================================================="

# Extract base URL of APK Studio from hydrated DOWNLOAD_URL
BASE_URL=""
if [[ "{{DOWNLOAD_URL}}" =~ ^https?:// ]]; then
    BASE_URL=$(echo "{{DOWNLOAD_URL}}" | cut -d'?' -f1)
fi

# SSOT Download & Compile Engine
download_and_compile() {
    local url="$1"
    local proj_name="$2"
    
    if [ -z "$url" ] || [[ "$url" == \{\{* ]]; then
        return 1
    fi
    
    echo "Re-downloading latest project updates for $proj_name..."
    cd ~
    if curl -fsSLk "$url" -o project_rebuild.zip; then
        rm -rf ~/apk_build
        mkdir -p ~/apk_build
        unzip -oq project_rebuild.zip -d ~/apk_build/
        rm project_rebuild.zip
        cd ~/apk_build
        chmod +x build.sh
        echo "Starting fresh compilation..."
        exec ./build.sh
    else
        return 2
    fi
}

if [ "$AUTO_EXIT" = "1" ] || [ "$NONINTERACTIVE" = "1" ]; then
    echo ""
    echo "✨ Automated build complete. Exiting session."
    exit 0
fi

while true; do
    echo ""
    echo "What would you like to do next?"
    echo "  [c] Clear the console"
    echo "  [r] Restart current build (recompile)"
    echo "  [y] Copy current console logs to clipboard"
    echo "  [Enter] Do nothing (exit to Termux)"
        
    # Reset quick variables
    QUICK_PID_1="" QUICK_PID_2="" QUICK_PID_3="" QUICK_PID_4="" QUICK_PID_5=""
        
    if [ ! -z "$BASE_URL" ]; then
        RECENT_FILE=$(mktemp)
        if curl -fsSLk "${BASE_URL}?ajax=get_recent_projects_text" -o "$RECENT_FILE" 2>/dev/null; then
            echo "  ------------------------------------------------"
            echo "  🚀 Quick Build shortcuts (recently updated):"
            while IFS='|' read -r num name pid; do
                echo "  [$num] Compile project: $name"
                eval "QUICK_PID_$num=\"$pid\""
                eval "QUICK_NAME_$num=\"$name\""
            done < "$RECENT_FILE"
            rm -f "$RECENT_FILE"
        fi
    fi

    echo "=================================================="
    if ! read -t 60 -p "Select [c/r/y/1-5/Enter] (auto-exit in 60s): " choice; then
        echo ""
        echo "⏰ Idle timeout reached (60s). Auto-closing session."
        exit 0
    fi
    case "$choice" in
        c|C)
            clear
            ;;
        y|Y)
            if command -v termux-clipboard-set &> /dev/null; then
                (echo '```text'; cat "$LOG_FILE" | sed -r "s/\x1B\[([0-9]{1,2}(;[0-9]{1,2})?)?[mGK]//g" | tr -d '\r'; echo ""; echo '```') | termux-clipboard-set
                echo "Console output copied to clipboard!"
            else
                echo "Error: termux-clipboard-set command not found."
                echo "Please install Termux:API app on Android and run: pkg install termux-api"
            fi
            ;;
        r|R)
            clear
            DOWNLOAD_URL="{{DOWNLOAD_URL}}"
            download_and_compile "$DOWNLOAD_URL" "{{APP_NAME}}"
            err=$?
            if [ $err -eq 1 ]; then
                echo "No download URL compiled. Performing local recompile..."
            elif [ $err -eq 2 ]; then
                echo "Re-download failed. Falling back to local recompile..."
            fi
            cd ~/apk_build 2>/dev/null && exec ./build.sh || echo "Error: Local build folder not found."
            ;;
        1|2|3|4|5)
            eval "TARGET_PID=\$QUICK_PID_$choice"
            eval "TARGET_NAME=\$QUICK_NAME_$choice"
            
            if [ ! -z "$TARGET_PID" ]; then
                echo "Quick building project: $TARGET_NAME (ID: $TARGET_PID)..."
                BUILD_URL="${BASE_URL}?ajax=download_zip&id=${TARGET_PID}"
                if ! download_and_compile "$BUILD_URL" "$TARGET_NAME"; then
                    echo "Error: Failed to download and compile $TARGET_NAME"
                fi
            else
                echo "Invalid shortcut selection"
            fi
            ;;
        *)
            echo "Exiting..."
            break 2>/dev/null || exit
            ;;
    esac
done