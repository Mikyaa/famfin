// Prints the text of the first QR code found in an image (receipt photo), or nothing.
// Usage: FAMFIN_TOOLS=/path/with/node_modules node qr.mjs photo.jpg
import { createRequire } from 'module';

const require = createRequire((process.env.FAMFIN_TOOLS || process.cwd()) + '/package.json');
const jsQR = require('jsqr');
const { Jimp } = require('jimp');

const file = process.argv[2];
if (!file) process.exit(2);

const original = await Jimp.read(file);
// A receipt QR is small in a large photo: try a few sizes, then a high-contrast pass
const widths = [...new Set([Math.min(original.bitmap.width, 2000), 1400, 1000, 700].filter(w => w <= original.bitmap.width))];
for (const contrast of [false, true]) {
  for (const w of widths) {
    const img = original.clone();
    if (img.bitmap.width !== w) img.resize({ w });
    if (contrast) img.greyscale().contrast(0.4);
    const { data, width, height } = img.bitmap;
    const code = jsQR(new Uint8ClampedArray(data.buffer, data.byteOffset, data.length), width, height, { inversionAttempts: 'attemptBoth' });
    if (code && code.data) {
      process.stdout.write(code.data);
      process.exit(0);
    }
  }
}
process.exit(1);
