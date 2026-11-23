// Renders the PNG icons from their SVGs. Run through icons.sh, which provides @resvg/resvg-js.
//   node build-files/icons.cjs REPOSITORY_ROOT
'use strict';

const fs = require('fs');
const path = require('path');
const { Resvg } = require('@resvg/resvg-js');

const root = process.argv[2];

if (!root) {
    console.error('usage: node build-files/icons.cjs REPOSITORY_ROOT');
    process.exit(2);
}

// The favicon for browsers that cannot read SVG icons, and the icon an iPhone puts on its home screen (iPhone rounds the
// corners itself, so its source fills the square).
for (const [source, target, width] of [
    ['public/assets/favicon.svg', 'public/assets/favicon-32.png', 32],
    ['build-files/apple-touch-icon.svg', 'public/assets/apple-touch-icon.png', 180],
]) {
    const svg = fs.readFileSync(path.join(root, source));
    const png = new Resvg(svg, { fitTo: { mode: 'width', value: width } }).render().asPng();
    fs.writeFileSync(path.join(root, target), png);
    console.log(target + ' (' + width + 'px)');
}
