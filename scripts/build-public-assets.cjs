const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const root = path.resolve(__dirname, '..');
const version = file => crypto.createHash('sha256').update(fs.readFileSync(path.join(root,file))).digest('hex').slice(0,16);
const iconVersion=version('icon-512.png');
const manifestPath=path.join(root,'manifest.json');
const manifest=JSON.parse(fs.readFileSync(manifestPath,'utf8'));
for (const icon of manifest.icons) {
  if (icon.src.split('?')[0]!=='icon-512.png') throw Error('Review the public icon allowlist before building.');
  icon.src='icon-512.png?v='+iconVersion;
}
fs.writeFileSync(manifestPath,JSON.stringify(manifest,null,4)+'\n');
const files=['manifest.json','icon-512.png'];
for (const directory of ['assets/css','assets/js']) {
  for (const name of fs.readdirSync(path.join(root,directory)).sort()) {
    if (/^[a-zA-Z0-9][a-zA-Z0-9_.-]*\.(css|js)$/.test(name)) {
      const candidate=path.join(root,directory,name);
      if (fs.lstatSync(candidate).isSymbolicLink() || !fs.statSync(candidate).isFile()) throw Error('Only ordinary public asset files may be cached.');
      files.push(directory+'/'+name);
    }
  }
}
const urls=files.sort().map(file=>file+'?v='+version(file));
const release=crypto.createHash('sha256').update(JSON.stringify(urls)).digest('hex').slice(0,16);
const generated='// BEGIN GENERATED PUBLIC ASSETS\nconst PUBLIC_ASSET_VERSION = '+JSON.stringify(release)+';\nconst STATIC_ASSETS = '+JSON.stringify(urls,null,4)+';\n// END GENERATED PUBLIC ASSETS';
const workerPath=path.join(root,'sw.js'), worker=fs.readFileSync(workerPath,'utf8');
const region=/\/\/ BEGIN GENERATED PUBLIC ASSETS[\s\S]*?\/\/ END GENERATED PUBLIC ASSETS/;
if (!region.test(worker)) throw Error('Missing service worker asset generation markers.');
fs.writeFileSync(workerPath,worker.replace(region,generated));
console.log('Versioned '+urls.length+' public assets; worker '+release);
