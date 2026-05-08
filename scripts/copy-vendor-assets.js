const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const assetsDir = path.join(root, 'assets', 'js');

fs.mkdirSync(assetsDir, { recursive: true });

const files = [
  {
    from: path.join(root, 'node_modules', 'alpinejs', 'dist', 'cdn.min.js'),
    to: path.join(assetsDir, 'alpine.min.js')
  },
  {
    from: path.join(root, 'node_modules', 'chart.js', 'dist', 'chart.umd.min.js'),
    to: path.join(assetsDir, 'chart.umd.min.js')
  }
];

for (const file of files) {
  fs.copyFileSync(file.from, file.to);
}
