const { spawn } = require('child_process');
const path = require('path');

const frontendDir = path.resolve(__dirname, '..');

const isWindows = process.platform === 'win32';
const npmCommand = isWindows ? 'npm.cmd' : 'npm';

console.log('Starting concurrent Local and Production builds...\n');

const local = spawn(
  npmCommand,
  ['run', 'build:local'],
  {
    cwd: frontendDir,
    stdio: 'inherit',
    shell: isWindows,
  }
);

const prod = spawn(
  npmCommand,
  ['run', 'build:prod'],
  {
    cwd: frontendDir,
    stdio: 'inherit',
    shell: isWindows,
  }
);

let localExitCode = null;
let prodExitCode = null;

function checkCompletion() {
  if (localExitCode === null || prodExitCode === null) {
    return;
  }

  const localSuccess = localExitCode === 0;
  const prodSuccess = prodExitCode === 0;

  if (localSuccess && prodSuccess) {
    console.log(`
========================================
ASSESSOR BUILD COMPLETE
========================================

LOCAL:
  Build:    SUCCESS
  Output:   assessor-frontend/build-local/
  Theme:    assessor-local-theme/assets/

PRODUCTION:
  Build:    SUCCESS
  Output:   assessor-frontend/build-prod/
  Theme:    assessor-live-theme/assets/

========================================
`);
    process.exit(0);
  } else {
    console.error(`
========================================
ASSESSOR BUILD FAILED
========================================

LOCAL:       ${localSuccess ? 'SUCCESS' : 'FAILED'}
PRODUCTION:  ${prodSuccess ? 'SUCCESS' : 'FAILED'}

See the build output above.
`);
    process.exit(1);
  }
}

local.on('close', code => {
  localExitCode = code !== null ? code : 1;
  checkCompletion();
});

local.on('error', err => {
  console.error('Failed to spawn local build process:', err);
  localExitCode = 1;
  checkCompletion();
});

prod.on('close', code => {
  prodExitCode = code !== null ? code : 1;
  checkCompletion();
});

prod.on('error', err => {
  console.error('Failed to spawn production build process:', err);
  prodExitCode = 1;
  checkCompletion();
});
