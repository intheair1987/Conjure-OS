#!/bin/bash
# ConjureRuntime Phase 1 Build Script

APP_NAME="ConjureRuntime"
PKG_NAME="com.conjure.runtime"
PROJ_DIR=$(pwd)
BUILD_DIR="$PROJ_DIR/build"

# Record the complete build session for the shared post-build console actions.
LOG_FILE="$PROJ_DIR/build.log"
rm -f "$LOG_FILE"
exec > >(tee -a "$LOG_FILE") 2>&1

set -e

get_package_name() {
    case "$1" in
        zipalign) echo "aapt" ;;
        aapt2) echo "aapt" ;;
        readelf) echo "binutils" ;;
        go) echo "golang" ;;
        *) echo "$1" ;;
    esac
}

echo "Checking dependencies..."
for cmd in aapt2 ecj d8 apksigner zip zipalign apt dpkg-deb readelf patchelf pkg go; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        pkg_name=$(get_package_name "$cmd")
        echo "Installing missing package: $pkg_name (for $cmd)..."
        pkg install -y "$pkg_name"
    fi
done

resolve_abi() {
    case "$(uname -m)" in
        aarch64) echo "arm64-v8a" ;;
        armv7l|armv8l) echo "armeabi-v7a" ;;
        x86_64) echo "x86_64" ;;
        i686) echo "x86" ;;
        *) echo "arm64-v8a" ;;
    esac
}

ABI="$(resolve_abi)"
NATIVE_LIB_DIR="$PROJ_DIR/lib/$ABI"
RUNTIME_STAGE="$BUILD_DIR/runtime-stage"
PACKAGE_CACHE="$HOME/.apkstudio/conjure-runtime-packages/$ABI"
LIB_CACHE="$HOME/.apkstudio/conjure-runtime-libs/$ABI"
TS_HASH_FILE="$LIB_CACHE/ts_proxy.hash"

rm -rf "$NATIVE_LIB_DIR"
mkdir -p "$NATIVE_LIB_DIR" "$RUNTIME_STAGE" "$PACKAGE_CACHE" "$LIB_CACHE"

CURRENT_TS_HASH=""
if [ -f "$PROJ_DIR/src_go/ts_proxy.go" ]; then
    CURRENT_TS_HASH=$(md5sum "$PROJ_DIR/src_go/ts_proxy.go" | awk '{print $1}')
fi

has_cached_libs() {
    [ -f "$LIB_CACHE/libphp.so" ] && \
    [ -f "$LIB_CACHE/libnginx.so" ] && \
    [ -f "$LIB_CACHE/libopenssl.so" ] && \
    [ -f "$LIB_CACHE/libtailscaled.so" ] && \
    [ -f "$LIB_CACHE/libtailscale.so" ] && \
    [ -f "$LIB_CACHE/libifconfig.so" ] && \
    [ -f "$LIB_CACHE/libip.so" ] && \
    [ -f "$LIB_CACHE/patched_ts_v2.marker" ]
}

if has_cached_libs; then
    echo "Cache hit: Reusing pre-compiled native libraries and libtsnet.so from $LIB_CACHE (0s compile)"
    cp -p "$LIB_CACHE"/* "$NATIVE_LIB_DIR/"
else
    echo "Resolving standalone Android runtime packages for ABI: $ABI..."

    has_cached_debs() {
        [ -n "$(find "$PACKAGE_CACHE" -maxdepth 1 -type f -name "php_*.deb" 2>/dev/null)" ] && \
        [ -n "$(find "$PACKAGE_CACHE" -maxdepth 1 -type f -name "nginx_*.deb" 2>/dev/null)" ] && \
        [ -n "$(find "$PACKAGE_CACHE" -maxdepth 1 -type f -name "openssl-tool_*.deb" 2>/dev/null)" ]
    }

    if ! has_cached_debs; then
        echo "Cache miss: Downloading runtime packages to $PACKAGE_CACHE..."
        pkg update -y
        pkg install -y php nginx openssl-tool patchelf binutils
        cd "$PACKAGE_CACHE"
        apt download php nginx openssl-tool
        cd "$PROJ_DIR"
    else
        echo "Cache hit: Reusing local packages from $PACKAGE_CACHE (0s download)"
    fi

    extract_package_binary() {
        local package_pattern="$1"
        local binary_name="$2"
        local output_so_name="$3"
        local package_file

        package_file="$(find . -maxdepth 1 -type f -name "${package_pattern}_*.deb" | head -n 1)"
        if [ -z "$package_file" ]; then
            package_file="$(find "$PACKAGE_CACHE" -maxdepth 1 -type f -name "${package_pattern}_*.deb" | head -n 1)"
        fi

        if [ -z "$package_file" ]; then
            echo "Unable to locate downloaded package for $package_pattern"
            exit 1
        fi

        local package_stage="$RUNTIME_STAGE/$package_pattern"
        rm -rf "$package_stage"
        mkdir -p "$package_stage"
        dpkg-deb -x "$package_file" "$package_stage"

        local source_binary="$package_stage/data/data/com.termux/files/usr/bin/$binary_name"
        if [ ! -f "$source_binary" ]; then
            source_binary="$package_stage/data/data/com.termux/files/usr/bin/$package_pattern"
        fi
        if [ ! -f "$source_binary" ]; then
            echo "Binary $binary_name was not found in $package_file"
            exit 1
        fi

        cp "$source_binary" "$NATIVE_LIB_DIR/$output_so_name"
        chmod 755 "$NATIVE_LIB_DIR/$output_so_name"
    }

    extract_package_binary "php" "php" "libphp.so"
    extract_package_binary "nginx" "nginx" "libnginx.so"
    extract_package_binary "openssl-tool" "openssl" "libopenssl.so"

    echo "Downloading patched Tailscale binaries from bropines/tailscale-termux-cli..."
    TS_ARCH="arm64"
    case "$ABI" in
        arm64-v8a) TS_ARCH="arm64" ;;
        armeabi-v7a) TS_ARCH="arm" ;;
        x86_64) TS_ARCH="amd64" ;;
        x86) TS_ARCH="386" ;;
    esac
    
    API_JSON=$(curl -s https://api.github.com/repos/bropines/tailscale-termux-cli/releases)
    
    # Try tar.gz first
    TS_RELEASE_URL=$(echo "$API_JSON" | grep "browser_download_url" | grep -i "$TS_ARCH\|aarch64" | grep -i "\.tar\.gz\|\.tgz" | cut -d '"' -f 4 | head -n 1)
    
    # If not found, try .deb
    if [ -z "$TS_RELEASE_URL" ]; then
        TS_RELEASE_URL=$(echo "$API_JSON" | grep "browser_download_url" | grep -i "$TS_ARCH\|aarch64" | grep -i "\.deb" | cut -d '"' -f 4 | head -n 1)
    fi
    
    if [ -n "$TS_RELEASE_URL" ]; then
        echo "Found release URL: $TS_RELEASE_URL"
        FILENAME=$(basename "$TS_RELEASE_URL")
        curl -fsSL "$TS_RELEASE_URL" -o "$RUNTIME_STAGE/$FILENAME"
        mkdir -p "$RUNTIME_STAGE/tailscale_bin"
        
        if [[ "$FILENAME" == *.deb ]]; then
            dpkg-deb -x "$RUNTIME_STAGE/$FILENAME" "$RUNTIME_STAGE/tailscale_bin"
        else
            tar -xzf "$RUNTIME_STAGE/$FILENAME" -C "$RUNTIME_STAGE/tailscale_bin"
        fi
        
        TSD_BIN=$(find "$RUNTIME_STAGE/tailscale_bin" -type f -name "tailscaled" | head -n 1)
        TS_BIN=$(find "$RUNTIME_STAGE/tailscale_bin" -type f -name "tailscale" | head -n 1)
        
        if [ -n "$TSD_BIN" ] && [ -n "$TS_BIN" ]; then
            cp "$TSD_BIN" "$NATIVE_LIB_DIR/libtailscaled.so"
            cp "$TS_BIN" "$NATIVE_LIB_DIR/libtailscale.so"
            chmod 755 "$NATIVE_LIB_DIR/libtailscaled.so" "$NATIVE_LIB_DIR/libtailscale.so"
            
            echo '#!/system/bin/sh' > "$NATIVE_LIB_DIR/libifconfig.so"
            echo 'echo "wlan0: flags=4163<UP,BROADCAST,RUNNING,MULTICAST>  mtu 1500"' >> "$NATIVE_LIB_DIR/libifconfig.so"
            echo 'echo "        inet 192.168.1.100  netmask 255.255.255.0  broadcast 192.168.1.255"' >> "$NATIVE_LIB_DIR/libifconfig.so"
            echo 'exit 0' >> "$NATIVE_LIB_DIR/libifconfig.so"
            
            echo '#!/system/bin/sh' > "$NATIVE_LIB_DIR/libip.so"
            echo 'echo "1: lo: <LOOPBACK,UP,LOWER_UP> mtu 65536 qdisc noqueue state UNKNOWN group default qlen 1000"' >> "$NATIVE_LIB_DIR/libip.so"
            echo 'echo "    link/loopback 00:00:00:00:00:00 brd 00:00:00:00:00:00"' >> "$NATIVE_LIB_DIR/libip.so"
            echo 'echo "    inet 127.0.0.1/8 scope host lo"' >> "$NATIVE_LIB_DIR/libip.so"
            echo 'echo "2: wlan0: <BROADCAST,MULTICAST,UP,LOWER_UP> mtu 1500 qdisc mq state UP group default qlen 1000"' >> "$NATIVE_LIB_DIR/libip.so"
            echo 'echo "    link/ether 02:00:00:00:00:00 brd ff:ff:ff:ff:ff:ff"' >> "$NATIVE_LIB_DIR/libip.so"
            echo 'echo "    inet 192.168.1.100/24 brd 192.168.1.255 scope global wlan0"' >> "$NATIVE_LIB_DIR/libip.so"
            echo 'exit 0' >> "$NATIVE_LIB_DIR/libip.so"
            
            chmod 755 "$NATIVE_LIB_DIR/libifconfig.so" "$NATIVE_LIB_DIR/libip.so"
        else
            echo "Error: tailscaled or tailscale binary not found in release archive."
            find "$RUNTIME_STAGE/tailscale_bin"
            exit 1
        fi
    else
        echo "Error: Could not find $TS_ARCH release for tailscale-termux-cli."
        echo "API Response excerpt:"
        echo "$API_JSON" | grep "browser_download_url" || echo "No assets found."
        exit 1
    fi

    collect_runtime_libs() {
        local target_binary="$1"
        local dependency

        while IFS= read -r dependency; do
            [ -n "$dependency" ] || continue
            case "$dependency" in
                libc.so|libdl.so|libm.so|liblog.so|libandroid.so|libc++_shared.so)
                    continue
                    ;;
            esac

            local source_library="$PREFIX/lib/$dependency"
            local destination_library="$NATIVE_LIB_DIR/$dependency"

            if [ -f "$source_library" ] && [ ! -f "$destination_library" ]; then
                cp "$source_library" "$destination_library"
                collect_runtime_libs "$source_library"
            fi
        done < <(readelf -d "$target_binary" 2>/dev/null | sed -n 's/.*Shared library: \[\(.*\)\].*/\1/p')
    }

    collect_runtime_libs "$NATIVE_LIB_DIR/libphp.so"
    collect_runtime_libs "$NATIVE_LIB_DIR/libnginx.so"
    collect_runtime_libs "$NATIVE_LIB_DIR/libopenssl.so"
    collect_runtime_libs "$NATIVE_LIB_DIR/libtailscaled.so"
    collect_runtime_libs "$NATIVE_LIB_DIR/libtailscale.so"

    echo "Sanitizing shared libraries and updating ELF headers..."
    cd "$NATIVE_LIB_DIR"

    # 1. Rename any libraries ending in .so.X (like libz.so.1) to strictly end in .so
    for file in *; do
        if [[ "$file" =~ \.so\.[0-9]+ ]]; then
            clean_name=$(echo "$file" | sed -E 's/\.so\.[0-9]+.*/.so/')
            mv "$file" "$clean_name"
        fi
    done

    # 2. Patch ELF headers: set SONAME and replace NEEDED versioned names with clean .so names
    for file in *; do
        if [ -f "$file" ]; then
            patchelf --set-soname "$file" "$file" 2>/dev/null || true
            for dep in $(readelf -d "$file" 2>/dev/null | grep NEEDED | awk -F'[' '{print $2}' | awk -F']' '{print $1}'); do
                if [[ "$dep" =~ \.so\.[0-9]+ ]]; then
                    clean_dep=$(echo "$dep" | sed -E 's/\.so\.[0-9]+.*/.so/')
                    patchelf --replace-needed "$dep" "$clean_dep" "$file" 2>/dev/null || true
                fi
            done
        fi
    done
    cd "$PROJ_DIR"

    # Save processed binaries to library cache for instant future builds
    cp -p "$NATIVE_LIB_DIR"/* "$LIB_CACHE/"
    touch "$LIB_CACHE/patched_ts_v2.marker"
fi

if [ ! -s "$NATIVE_LIB_DIR/libphp.so" ]; then
    echo "Provisioned libphp.so executable is missing or empty."
    exit 1
fi

if [ ! -s "$NATIVE_LIB_DIR/libnginx.so" ]; then
    echo "Provisioned libnginx.so executable is missing or empty."
    exit 1
fi

if [ ! -s "$NATIVE_LIB_DIR/libopenssl.so" ]; then
    echo "Provisioned libopenssl.so executable is missing or empty."
    exit 1
fi

rm -f ./*.deb
rm -rf "$RUNTIME_STAGE"

ANDROID_JAR=$(find "$PREFIX/share" -name "android.jar" 2>/dev/null | head -n 1)
if [ -z "$ANDROID_JAR" ]; then
    ANDROID_JAR="/system/framework/framework.jar"
fi

rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR/gen" "$BUILD_DIR/obj" "$BUILD_DIR/apk"

echo "Compiling resources..."
aapt2 compile --dir res/ -o "$BUILD_DIR/compiled_res.zip"
aapt2 link "$BUILD_DIR/compiled_res.zip" \
    -I /system/framework/framework-res.apk \
    --manifest AndroidManifest.xml \
    --java "$BUILD_DIR/gen" \
    -o "$BUILD_DIR/apk/app-unsigned.apk"

echo "Compiling Java sources..."
ecj -d "$BUILD_DIR/obj" \
    -cp "$ANDROID_JAR" \
    $(find "$BUILD_DIR/gen" -name "*.java") \
    $(find src -name "*.java")

echo "Converting classes to DEX..."
d8 --lib "$ANDROID_JAR" \
    --output "$BUILD_DIR/apk/" \
    $(find "$BUILD_DIR/obj" -name "*.class")

echo "Packaging APK and native libraries..."
cd "$BUILD_DIR/apk"
zip -q -ur app-unsigned.apk classes.dex
cd "$PROJ_DIR"

if [ -d "lib" ]; then
    echo "Bundling native libraries (lib/)..."
    zip -q -ur "$BUILD_DIR/apk/app-unsigned.apk" lib/
fi

if [ -d "assets" ]; then
    echo "Bundling WebView assets (assets/)..."
    # Remove build-time APT package indices from assets to keep APK small
    find assets/binaries -type f -name "termux-main-*" -delete 2>/dev/null || true
    zip -q -ur "$BUILD_DIR/apk/app-unsigned.apk" assets/
fi

echo "Aligning APK..."
cd "$BUILD_DIR/apk"
zipalign -f -p 4 app-unsigned.apk app-aligned.apk
cd "$PROJ_DIR"

echo "Signing..."
KEYSTORE="$HOME/.apkstudio/debug.keystore"
if [ ! -f "$KEYSTORE" ]; then
    mkdir -p "$HOME/.apkstudio"
    keytool -genkey -v \
        -keystore "$KEYSTORE" \
        -storepass android \
        -alias androiddebugkey \
        -keypass android \
        -keyalg RSA \
        -keysize 2048 \
        -validity 10000 \
        -dname "CN=Android Debug,O=Android,C=US"
fi

mkdir -p "/sdcard/Download"
apksigner sign \
    --ks "$KEYSTORE" \
    --ks-pass pass:android \
    --out "/sdcard/Download/${APP_NAME}-debug.apk" \
    "$BUILD_DIR/apk/app-aligned.apk"

rm -rf "$BUILD_DIR"
rm -rf "$PROJ_DIR/lib"
echo "Build complete: /sdcard/Download/${APP_NAME}-debug.apk"