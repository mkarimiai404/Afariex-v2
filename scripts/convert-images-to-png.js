#!/usr/bin/env node
const fs = require("fs/promises");
const path = require("path");
const sharp = require("sharp");

const imagesDir = path.resolve(process.cwd(), "assets/images");

async function main() {
  const entries = await fs.readdir(imagesDir, { withFileTypes: true });
  const imageFiles = entries
    .filter((entry) => entry.isFile())
    .map((entry) => entry.name)
    .filter((name) => /\.(png|jpe?g|webp)$/i.test(name));

  if (imageFiles.length === 0) {
    console.log("No image files found in assets/images.");
    return;
  }

  for (const fileName of imageFiles) {
    const filePath = path.join(imagesDir, fileName);
    const tempPath = `${filePath}.tmp`;

    try {
      await sharp(filePath).png({ compressionLevel: 9 }).toFile(tempPath);
      await fs.rename(tempPath, filePath);
      console.log(`Converted: ${fileName} -> PNG`);
    } catch (error) {
      await fs.rm(tempPath, { force: true });
      console.error(`Failed: ${fileName}`, error.message);
    }
  }
}

main().catch((error) => {
  console.error("Unexpected error:", error);
  process.exit(1);
});
