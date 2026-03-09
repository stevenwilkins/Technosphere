# Technosphere Ecosystem Build

This version expands the dry terrain game into a larger live ecosystem.

## Changes in this build

- Food is much more sparse across a much larger map
- Added prey and predators
- Prey use flocking-style movement
- Fauna can stop, rest, sleep, and mate when old enough
- Body rendering supports longer torsos, larger heads, longer legs, and tails
- The browser-linked creature keeps using the existing cookie-based identity
- Smooth third-person camera tracks the browser-linked creature
- The creature editor now includes:
  - limbs
  - size
  - body length
  - head size
  - leg length
  - tail length
  - hue
  - crest

## Run

```bash
php -S localhost:8000
```

Open:

```text
http://localhost:8000/index.php
```

## Notes

- The extra wildlife is simulated in the browser.
- The browser-linked main creature is still persisted through PHP using SQLite when available, or the fallback JSON store otherwise.
- Older saved creatures are upgraded automatically with default values for the new body genes.
