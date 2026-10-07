FROM lscr.io/linuxserver/transmission:4.1.3

# linuxserver runs root-owned scripts from /custom-cont-init.d after its own config step
# and before starting transmission-daemon.
COPY --chmod=755 write-settings.sh /custom-cont-init.d/10-write-settings.sh
