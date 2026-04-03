# Qt5 to Qt6 Migration with Wayland Support

Upgrade a Qt5 C++ application to Qt6 and add native Wayland support on GNOME. This skill covers the full migration path based on real-world experience with ksnip.

## Step 1: Wayland Auto-Detection (main.cpp)

Add before `QApplication` constructor, so Qt uses native Wayland backend instead of XWayland:

```cpp
#ifdef Q_OS_UNIX
if (qEnvironmentVariableIsEmpty("QT_QPA_PLATFORM") &&
    !qEnvironmentVariableIsEmpty("WAYLAND_DISPLAY")) {
    qputenv("QT_QPA_PLATFORM", "wayland");
}
#endif
```

**Why before QApplication?** Qt reads `QT_QPA_PLATFORM` during platform plugin init inside the `QApplication` constructor.

## Step 2: CMakeLists.txt — Switch to Qt6

Replace:
```cmake
find_package(Qt5 ${QT_MIN_VERSION} REQUIRED ${QT_COMPONENTS})
```
With:
```cmake
option(BUILD_WITH_QT6 "Build against Qt6" OFF)
if (BUILD_WITH_QT6)
    set(QT_MAJOR_VERSION 6)
else()
    set(QT_MAJOR_VERSION 5)
endif()
find_package(Qt${QT_MAJOR_VERSION} ${QT_MIN_VERSION} REQUIRED ${QT_COMPONENTS})
```

Use `Qt${QT_MAJOR_VERSION}::Widgets` etc. for target linking.

**Qt6 requires:** `qt6-base-dev` and `qt6-base-private-dev` (for `Qt6::GuiPrivate`).

## Step 3: Remove Deprecated Qt5 Attributes

Guard or remove attributes that don't exist in Qt6:

```cpp
#if QT_VERSION < QT_VERSION_CHECK(6, 0, 0)
QApplication::setAttribute(Qt::AA_EnableHighDpiScaling);
#endif

QApplication app(argc, argv);

#if QT_VERSION < QT_VERSION_CHECK(6, 0, 0)
app.setAttribute(Qt::AA_UseHighDpiPixmaps);
#endif
```

Qt6 enables high-DPI scaling by default — these attributes are removed.

## Step 4: Fix QSharedPointer

Qt6 forward-declares `QSharedPointer` in `qmetatype.h` which causes incomplete type errors. Add explicit includes:

```cpp
#include <QSharedPointer>
```

This is the most common Qt6 build breakage.

## Step 5: QSystemTrayIcon → AppIndicator (GNOME Wayland)

`QSystemTrayIcon` does NOT work reliably on GNOME Wayland. It uses XEmbed/temp PNG paths that GNOME's AppIndicator extension ignores.

**Solution:** Use `libayatana-appindicator3` on Linux:

### CMakeLists.txt:
```cmake
if (UNIX AND NOT APPLE)
    include(FindPkgConfig)
    pkg_check_modules(AYATANA_APPINDICATOR REQUIRED ayatana-appindicator3-0.1)
endif()
```

In target:
```cmake
target_include_directories(myapp PRIVATE ${AYATANA_APPINDICATOR_INCLUDE_DIRS})
target_link_libraries(myapp ${AYATANA_APPINDICATOR_LIBRARIES})
```

### GTK/Qt Header Conflict:
GTK headers define `signals` as a struct member which conflicts with Qt's `signals` keyword:

```cpp
#undef signals
#include <libayatana-appindicator/app-indicator.h>
#include <gtk/gtk.h>
#define signals Q_SIGNALS
```

### AppIndicator Implementation Pattern:
```cpp
// Constructor:
gtk_init(nullptr, nullptr);
mIndicator = app_indicator_new_with_path("myapp", "myapp-icon",
    APP_INDICATOR_CATEGORY_APPLICATION_STATUS,
    "/usr/share/icons/hicolor/scalable/apps");
app_indicator_set_status(mIndicator, APP_INDICATOR_STATUS_PASSIVE);

// Enable:
app_indicator_set_status(mIndicator, APP_INDICATOR_STATUS_ACTIVE);

// Hide:
app_indicator_set_status(mIndicator, APP_INDICATOR_STATUS_PASSIVE);

// Menu: build with gtk_menu_new(), gtk_menu_item_new_with_label(), etc.
// Use app_indicator_set_menu() to attach.
```

### GTK→Qt Event Bridge:
GTK callbacks fire from GLib main loop. Qt5 on Linux uses `QEventDispatcherGlib` so `action->trigger()` works directly in GTK callbacks. For safety, store action pointers via `g_object_set_data()` on menu items.

### Dependencies:
```bash
sudo apt install libayatana-appindicator3-dev
```

## Step 6: Window Activation on Wayland

On Wayland, `activateWindow()` and `raise()` don't work in Qt5 — the compositor blocks focus-stealing.

**Qt6 fix:** Qt6 implements `xdg_activation_v1` protocol natively. Just call:
```cpp
mWidget->show();
mWidget->raise();
mWidget->activateWindow();  // works on Qt6 Wayland!
```

**Qt5 workaround** (flickery, not recommended):
```cpp
auto flags = mWidget->windowFlags();
mWidget->setWindowFlags(flags | Qt::WindowStaysOnTopHint);
mWidget->show();
QTimer::singleShot(100, [this, flags]() {
    mWidget->setWindowFlags(flags);
    mWidget->show();
});
```

## Step 7: Avoid Black Screen on Startup

On Wayland, showing a window before its content is ready causes a black frame. Ensure setup order:

```cpp
// WRONG order:
handleGuiStartup();     // shows window
setupImageAnnotator();  // renders content

// CORRECT order:
setupImageAnnotator();  // renders content first
resize(minimumSize());
loadSettings();
handleGuiStartup();     // then show window
```

If using visibility handlers with `setVisible(true/false)` — prefer opacity approach (`setWindowOpacity(0.0/1.0)`) which avoids window re-mapping on Wayland.

## Step 8: Desktop File

Use bare executable name for `Exec=`, not hardcoded path:
```ini
Exec=myapp %F
```
Not `Exec=/usr/bin/myapp %F` — this breaks dock icon matching when installed to `/usr/local/bin/`.

## Common GNOME Wayland Pitfall: indicator-application-service

On Ubuntu with GNOME, `indicator-application-service` can hijack the `org.kde.StatusNotifierWatcher` DBus name before the GNOME shell extension gets it. All tray icons silently disappear.

**Diagnose:**
```bash
dbus-send --session --print-reply --dest=org.freedesktop.DBus \
  /org/freedesktop/DBus org.freedesktop.DBus.GetNameOwner \
  string:org.kde.StatusNotifierWatcher
# If owner is indicator-application-service (not gnome-shell) → problem
```

**Fix:**
```bash
mkdir -p ~/.config/autostart
cp /etc/xdg/autostart/indicator-application.desktop ~/.config/autostart/
echo "Hidden=true" >> ~/.config/autostart/indicator-application.desktop
# Kill running instance:
killall indicator-application-service
```

## Checklist

- [ ] Add Wayland auto-detection in main.cpp
- [ ] Update CMakeLists.txt for Qt6
- [ ] Guard/remove deprecated Qt5 attributes
- [ ] Fix `QSharedPointer` includes
- [ ] Replace `QSystemTrayIcon` with AppIndicator on Linux
- [ ] Verify window activation works (Qt6 native xdg_activation_v1)
- [ ] Fix startup order to prevent black screen
- [ ] Fix .desktop Exec path
- [ ] Check indicator-application-service not hijacking tray
- [ ] Install: `qt6-base-dev qt6-base-private-dev libayatana-appindicator3-dev`
