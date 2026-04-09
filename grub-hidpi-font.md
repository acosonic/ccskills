# GRUB HiDPI Font Fix

Fix unreadably small GRUB menu font on HiDPI/4K displays, especially on UEFI systems with Secure Boot.

## Problem

On HiDPI displays (e.g. 2880x1800), GRUB renders text at native resolution using a 16pt bitmap font, making the boot menu nearly unreadable. Secure Boot prevents loading custom fonts via `loadfont`, so replacing `unicode.pf2` or setting `GRUB_FONT` has no effect.

## Diagnosis

```bash
# Check display resolution
xrandr | grep -w connected

# Check if UEFI
[ -d /sys/firmware/efi ] && echo "UEFI" || echo "BIOS"

# Check Secure Boot status
mokutil --sb-state

# Check current GRUB font config
grep 'GRUB_TERMINAL\|GRUB_GFXMODE\|GRUB_FONT' /etc/default/grub

# Check what font GRUB actually loads (look for loadfont lines)
sudo grep 'loadfont\|gfxmode' /boot/grub/grub.cfg | head -10

# Check default font size
file /usr/share/grub/unicode.pf2
# Typically: GRUB2 font "GNU Unifont Regular 16" — too small for HiDPI
```

## Solution: Force Lower GFXMODE Resolution

The only reliable approach with Secure Boot enabled is to force GRUB to use a lower GOP resolution. This makes the built-in 16pt font appear larger on screen.

### Step 1: Configure `/etc/default/grub`

```bash
# Set gfxterm output and a lower resolution
sudo sed -i '/^GRUB_TERMINAL_OUTPUT/d; /^GRUB_GFXMODE/d; /^GRUB_FONT/d' /etc/default/grub

# Add the working configuration
cat <<'EOF' | sudo tee -a /etc/default/grub

# HiDPI fix: force lower resolution so font appears larger
GRUB_TERMINAL_OUTPUT="gfxterm"
GRUB_GFXMODE=1024x768
EOF
```

### Step 2: Regenerate GRUB config

```bash
sudo update-grub
```

### Step 3: Reboot and verify

The GRUB menu should now display at 1024x768 with a readable font size.

## Alternative Resolutions

If `1024x768` doesn't look right, try these (most UEFI GOP firmwares support them):

| Resolution | Effect |
|-----------|--------|
| `800x600` | Largest text, lowest detail |
| `1024x768` | Good balance (recommended) |
| `1280x1024` | Slightly smaller text |
| `1920x1080` | May still be too small on 4K |

Change with:
```bash
sudo sed -i 's/GRUB_GFXMODE=.*/GRUB_GFXMODE=800x600/' /etc/default/grub
sudo update-grub
```

## If Secure Boot Is Disabled

Without Secure Boot, GRUB can load custom large fonts directly:

```bash
# Generate a large font (e.g. 64pt or 96pt for 4K displays)
sudo grub-mkfont -s 64 -o /boot/grub/fonts/dejavu64.pf2 \
  /usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf

# Configure GRUB to use it at native resolution
sudo sed -i '/^GRUB_GFXMODE/d; /^GRUB_FONT/d' /etc/default/grub
cat <<'EOF' | sudo tee -a /etc/default/grub
GRUB_GFXMODE=auto
GRUB_FONT=/boot/grub/fonts/dejavu64.pf2
EOF

sudo update-grub
```

Scale the font size to your display:
- **2560x1440**: 48pt
- **2880x1800**: 64pt
- **3840x2160 (4K)**: 72-96pt

## Checking Supported GOP Modes

On systems without Secure Boot, press `c` at the GRUB menu and run:
```
videoinfo
```

With Secure Boot enabled, `videoinfo` is blocked ("prohibited"). Instead, check from Linux:
```bash
# Framebuffer modes
cat /sys/class/graphics/fb0/modes

# DRM modes (shows what GPU supports, not necessarily what GOP provides)
xrandr | grep -A1 connected
```

## Troubleshooting

### Font still tiny after changes
- Verify `update-grub` was run after editing `/etc/default/grub`
- Check that `GRUB_TERMINAL=console` is commented out (conflicts with `GRUB_TERMINAL_OUTPUT`)
- Confirm no leftover custom scripts in `/etc/grub.d/` (e.g. `09_bigfont`)

### GOP doesn't support the chosen resolution
GRUB falls back to `auto` (native). Add a fallback chain:
```
GRUB_GFXMODE=1024x768,800x600,auto
```

### Custom font not loading (Secure Boot)
`loadfont` silently fails under GRUB Secure Boot lockdown. Either:
1. Use the resolution approach above (recommended)
2. Disable Secure Boot in BIOS to allow custom fonts

## Notes

- This fix is persistent across `update-grub` and kernel updates since it lives in `/etc/default/grub`
- The `grub-efi` package update may reset `/etc/default/grub` — re-apply after major GRUB updates
- `GRUB_GFXPAYLOAD_LINUX=keep` can be added to pass the resolution to the Linux framebuffer console, but it does not affect the GRUB menu itself
