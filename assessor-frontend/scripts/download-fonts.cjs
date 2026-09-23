const fs = require('fs');
const path = require('path');
const https = require('https');

const root = path.resolve(__dirname, '..');
const dir = path.join(root, 'public', 'fonts');

const fonts = [
  {
    file: 'PlusJakartaSans-VariableFont.ttf',
    url: 'https://raw.githubusercontent.com/tokotype/PlusJakartaSans/master/fonts/variable/PlusJakartaSans[wght].ttf',
  },
  {
    file: 'SpaceMono-Regular.ttf',
    url: 'https://raw.githubusercontent.com/googlefonts/spacemono/main/fonts/ttf/SpaceMono-Regular.ttf',
  },
  {
    file: 'SpaceMono-Bold.ttf',
    url: 'https://raw.githubusercontent.com/googlefonts/spacemono/main/fonts/ttf/SpaceMono-Bold.ttf',
  },
];

function download(url, destination, redirectCount = 0) {
  return new Promise((resolve, reject) => {
    if (redirectCount > 5) {
      reject(new Error(`Too many redirects: ${url}`));
      return;
    }

    https.get(url, response => {
      if (
        response.statusCode >= 300 &&
        response.statusCode < 400 &&
        response.headers.location
      ) {
        response.resume();

        const nextUrl = new URL(
          response.headers.location,
          url
        ).toString();

        download(nextUrl, destination, redirectCount + 1)
          .then(resolve)
          .catch(reject);

        return;
      }

      if (response.statusCode !== 200) {
        response.resume();
        reject(
          new Error(
            `HTTP ${response.statusCode} while downloading ${url}`
          )
        );
        return;
      }

      const temp = `${destination}.download`;
      const output = fs.createWriteStream(temp);

      response.pipe(output);

      output.on('finish', () => {
        output.close(() => {
          try {
            fs.renameSync(temp, destination);
            resolve();
          } catch (error) {
            try {
              fs.unlinkSync(temp);
            } catch (_) {}

            reject(error);
          }
        });
      });

      output.on('error', error => {
        try {
          fs.unlinkSync(temp);
        } catch (_) {}

        reject(error);
      });
    }).on('error', reject);
  });
}

async function main() {
  fs.mkdirSync(dir, { recursive: true });

  for (const font of fonts) {
    const destination = path.join(dir, font.file);

    if (fs.existsSync(destination) && fs.statSync(destination).size > 0) {
      console.log(`✓ ${font.file} already exists`);
      continue;
    }

    console.log(`↓ Downloading ${font.file}`);

    await download(font.url, destination);

    if (
      !fs.existsSync(destination) ||
      fs.statSync(destination).size === 0
    ) {
      throw new Error(`Downloaded font is empty: ${font.file}`);
    }

    console.log(`✓ ${font.file}`);
  }

  console.log('Aistudio fonts are ready.');
}

main().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
