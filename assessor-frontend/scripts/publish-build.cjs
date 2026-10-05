const fs = require('fs');
const path = require('path');

const target = process.argv[2];

let buildDirName;
let themeDirName;
let modeLabel;

if (target === 'local') {
  buildDirName = 'build-local';
  themeDirName = 'assessor-local-theme';
  modeLabel = 'LOCAL';
} else if (target === 'prod') {
  buildDirName = 'build-prod';
  themeDirName = 'assessor-live-theme';
  modeLabel = 'PRODUCTION';
} else {
  console.error('Invalid build target. Expected "local" or "prod".');
  process.exit(1);
}

const repoRoot = path.resolve(__dirname, '..', '..');

const sourceDir = path.join(
  repoRoot,
  'assessor-frontend',
  buildDirName
);

const destinationDir = path.join(
  repoRoot,
  themeDirName,
  'assets'
);

// Custom theme assets to specifically preserve and NOT overwrite/delete from build
const PRESERVED_CUSTOM_ASSET_NAMES = new Set([
  'background.jpg',
  'background2.jpg',
  'fonts',
]);

function copyRecursive(src, dest) {
  const stat = fs.statSync(src);
  if (stat.isDirectory()) {
    if (!fs.existsSync(dest)) {
      fs.mkdirSync(dest, { recursive: true });
    }
    const entries = fs.readdirSync(src);
    for (const entry of entries) {
      copyRecursive(path.join(src, entry), path.join(dest, entry));
    }
  } else {
    fs.copyFileSync(src, dest);
  }
}

function publishBuild() {
  if (!fs.existsSync(sourceDir)) {
    console.error(`Error: Source build directory does not exist: ${sourceDir}`);
    console.error(`Build succeeded, but publishing to ${path.relative(repoRoot, destinationDir).replace(/\\/g, '/')} failed.`);
    process.exit(1);
  }

  try {
    if (!fs.existsSync(destinationDir)) {
      fs.mkdirSync(destinationDir, { recursive: true });
    }

    const destStaticDir = path.join(destinationDir, 'static');
    if (fs.existsSync(destStaticDir)) {
      fs.rmSync(destStaticDir, { recursive: true, force: true });
    }

    const buildEntries = fs.readdirSync(sourceDir);

    for (const item of buildEntries) {
      if (PRESERVED_CUSTOM_ASSET_NAMES.has(item)) {
        const destItemPath = path.join(destinationDir, item);
        if (fs.existsSync(destItemPath)) {
          continue;
        }
      }

      const srcPath = path.join(sourceDir, item);
      const destPath = path.join(destinationDir, item);

      copyRecursive(srcPath, destPath);
    }

    const relativeSource = path.relative(repoRoot, sourceDir).replace(/\\/g, '/');
    const relativeDest = path.relative(repoRoot, destinationDir).replace(/\\/g, '/');

    console.log('\nAssessor build published successfully.\n');
    console.log('Mode:');
    console.log(modeLabel);
    console.log('\nSource:');
    console.log(relativeSource);
    console.log('\nDestination:');
    console.log(relativeDest);
    console.log('\nStatic assets replaced:');
    console.log('YES\n');
  } catch (error) {
    console.error(`Build succeeded, but publishing to ${path.relative(repoRoot, destinationDir).replace(/\\/g, '/')} failed.`);
    console.error(`Source: ${sourceDir}`);
    console.error(`Destination: ${destinationDir}`);
    console.error('Filesystem error:', error);
    process.exit(1);
  }
}

publishBuild();
