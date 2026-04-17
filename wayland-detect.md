# Wayland Auto-Detection for Lazarus/Qt5 Applications

Automatically detects if the desktop session is running under Wayland and sets `QT_QPA_PLATFORM=wayland` so a Qt5-backed Lazarus application uses the native Wayland backend instead of XWayland — without requiring the user to set any environment variable manually.

## Pattern

Add this to the project's `.lpr` file, **before** `Application.Initialize`:

```pascal
program myapp;

{$mode delphi}{$H+}

uses
  {$IFDEF UNIX} cthreads, {$ENDIF}
  ...

{$IFDEF UNIX}
// Declare libc setenv directly — FPC RTL has no portable wrapper for this.
function setenv(name, value: PChar; replace: Integer): Integer; cdecl; external 'c';
{$ENDIF}

var
  ...
begin
  {$IFDEF UNIX}
  // Auto-detect Wayland: set QT_QPA_PLATFORM=wayland if running under a Wayland
  // compositor and the user has not already overridden the platform explicitly.
  if (GetEnvironmentVariable('QT_QPA_PLATFORM') = '') and
     (GetEnvironmentVariable('WAYLAND_DISPLAY') <> '') then begin
    setenv(PChar('QT_QPA_PLATFORM'), PChar('wayland'), 1);
  end;
  {$ENDIF}

  Application.Initialize;
  ...
end.
```

## Why libc `setenv` instead of FPC helpers?

| Option | Problem |
|---|---|
| `SetEnvironmentVariable` | Windows-only (WinAPI) |
| `fpSetEnv` | Not in FPC standard RTL (only in some Unix units, unreliable) |
| `setenv` from libc | Works on all POSIX systems; FPC can call it via `cdecl; external 'c'` |

`GetEnvironmentVariable` from `SysUtils` works fine for reading; only the write path needs the libc call.

## Why before `Application.Initialize`?

Qt reads `QT_QPA_PLATFORM` during platform plugin initialization, which happens inside `Application.Initialize`. Setting it after that point has no effect.

## Conditions checked

1. `QT_QPA_PLATFORM` is empty — respect any explicit user override (e.g. `QT_QPA_PLATFORM=xcb` to force X11).
2. `WAYLAND_DISPLAY` is non-empty — confirms a Wayland compositor is actually running. On a plain X11 session this variable is unset, so the block is skipped.

## Applied in

- The main `.lpr` file of any Lazarus project built against Qt5 that should run natively on Wayland.
