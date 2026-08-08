import ftp from 'basic-ftp';
import fs from 'fs';
import archiver from 'archiver';

function zipRemoteFiles(stagingDir, outputPath) {
    return new Promise((resolve, reject) => {
        const output = fs.createWriteStream(outputPath);
        const archive = archiver('zip', { zlib: { level: 9 } });

        output.on('close', () => resolve());
        archive.on('error', (err) => reject(err));
        archive.pipe(output);

        if (fs.existsSync(stagingDir)) {
            archive.directory(stagingDir, false); 
        } else {
            return reject(new Error("Staging directory missing!"));
        }

        archive.finalize();
    });
}

async function runBackup() {
    const client = new ftp.Client();
    client.ftp.verbose = true;
    const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
    const zipName = `app-remote-backup-${timestamp}.zip`;
    const localZipPath = `./${zipName}`;
    const stagingDir = "./remote-staging";

    try {
        await client.access({
            host: process.env.FTP_SERVER,
            user: process.env.FTP_USERNAME,
            password: process.env.FTP_PASSWORD,
            secure: false
        });

        // 1. Download existing production files from the GoDaddy subfolder
        console.log("Downloading current public_html/app contents for backup...");
        if (!fs.existsSync(stagingDir)) fs.mkdirSync(stagingDir);
        
        try {
            await client.downloadToDir(stagingDir, "/public_html/app");
        } catch (e) {
            console.log("Subfolder empty or new configuration. Proceeding with clean archive.");
        }

        // 2. Compress the downloaded files into a single ZIP archive for speed
        console.log("Compressing remote app files into a single ZIP file...");
        await zipRemoteFiles(stagingDir, localZipPath);

        // 3. Upload the compressed single ZIP file to the backup vault
        console.log("Creating backup directory on GoDaddy...");
        await client.ensureDir("/backups");
        console.log(`Uploading compressed backup (${zipName}) to GoDaddy vault...`);
        await client.uploadFrom(localZipPath, `/backups/${zipName}`);

        // 4. Enforce the 5 backup limit on GoDaddy vault
        await client.cd("/backups");
        let fileList = await client.list();
        fileList = fileList
            .filter(f => f.name.startsWith('app-remote-backup-') && f.name.endsWith('.zip'))
            .sort((a, b) => new Date(a.rawModifiedAt) - new Date(b.rawModifiedAt));

        if (fileList.length > 5) {
            const excessCount = fileList.length - 5;
            console.log(`Cleaning old GoDaddy backups. Found ${fileList.length} items.`);
            for (let i = 0; i < excessCount; i++) {
                await client.remove(fileList[i].name);
                console.log(`Deleted obsolete GoDaddy backup file: ${fileList[i].name}`);
            }
        }

        console.log("Remote backup completely captured and rotated successfully!");
    } catch (err) {
        console.error("Backup failed!", err);
        process.exit(1); 
    } finally {
        if (fs.existsSync(localZipPath)) fs.unlinkSync(localZipPath);
        if (fs.existsSync(stagingDir)) fs.rmSync(stagingDir, { recursive: true, force: true });
        client.close();
    }
}
runBackup();
