using Microsoft.Win32;
using System.Diagnostics;
using System.IO.Compression;
using System.Net;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;

namespace TablePlay.Setup;

internal enum InstallationState
{
    Fresh,
    IncompleteFresh,
    Repair,
}

internal sealed record LicenseTrustConfiguration(
    string Algorithm,
    string KeyId,
    string PublicKey,
    string PublicKeySha256,
    IReadOnlyDictionary<string, string> TrustedPublicKeys);

internal static class InstallerEngine
{
    private const int ServerPort = 8000;
    private const int ReverbPort = 8080;
    private const int DatabasePort = 3310;
    private const string DatabaseUser = "tableplay_app";
    private const string ServiceName = "TablePlayServer";
    private static readonly byte[] OverlayMagic = Encoding.ASCII.GetBytes("TABLEPLAY_PAYLOAD_V1");
    private static readonly TimeSpan ServiceStartupTimeout = TimeSpan.FromMinutes(5);
    private static readonly TimeSpan RuntimeValidationTimeout = TimeSpan.FromMinutes(2);

    public static async Task<InstallResult> InstallAsync(InstallOptions options, IProgress<InstallProgress> progress)
    {
        using var maintenance = new Semaphore(1, 1, @"Global\TablePlayMaintenance");
        var acquired = maintenance.WaitOne(TimeSpan.Zero);
        if (!acquired) throw new InvalidOperationException("Another TablePlay setup, backup, restore, or removal operation is already running.");
        try { return await InstallCoreAsync(options, progress); }
        finally { maintenance.Release(); }
    }

    private static async Task<InstallResult> InstallCoreAsync(InstallOptions options, IProgress<InstallProgress> progress)
    {
        var installRoot = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "TablePlay");
        string? recoveredInstallation = null;
        var installationState = GetInstallationState(installRoot);
        if (installationState == InstallationState.Repair)
            return await UpgradeAsync(installRoot, progress);
        if (installationState == InstallationState.IncompleteFresh)
        {
            recoveredInstallation = await ArchiveIncompleteInstallationAsync(installRoot);
        }

        EnsureRequiredPortsAreFree();

        var stagingRoot = Path.Combine(Path.GetTempPath(), "TablePlaySetup_" + Guid.NewGuid().ToString("N"));
        var temporaryDatabase = default(Process);
        Directory.CreateDirectory(stagingRoot);

        try
        {
            progress.Report(new InstallProgress(5, "Checking the installer package…"));
            ExtractOverlay(stagingRoot);
            var licenseTrust = ValidatePayload(stagingRoot);

            progress.Report(new InstallProgress(14, "Installing private TablePlay components…"));
            Directory.CreateDirectory(installRoot);
            File.WriteAllText(Path.Combine(installRoot, ".installation-in-progress"), DateTimeOffset.Now.ToString("O"));
            CopyDirectory(stagingRoot, installRoot);

            progress.Report(new InstallProgress(18, "Installing required Windows components..."));
            InstallVisualCppRuntime(installRoot);

            var localIp = FindLocalIPv4Address();
            var databasePassword = RandomToken(36);
            var appKey = "base64:" + Convert.ToBase64String(RandomNumberGenerator.GetBytes(32));
            var reverbSecret = RandomToken(48);

            Directory.CreateDirectory(Path.Combine(installRoot, "config"));
            Directory.CreateDirectory(Path.Combine(installRoot, "logs"));
            Directory.CreateDirectory(Path.Combine(installRoot, "backups"));
            Directory.CreateDirectory(Path.Combine(installRoot, "server", "storage", "app", "updates"));

            progress.Report(new InstallProgress(24, "Configuring the local database…"));
            WriteDatabaseConfiguration(installRoot, databasePassword);
            await InitializeDatabaseAsync(installRoot, databasePassword, progress);

            progress.Report(new InstallProgress(35, "Starting the private database…"));
            var databaseInitialization = WriteDatabaseInitializationScript(installRoot, databasePassword);
            try
            {
                temporaryDatabase = StartTemporaryDatabase(installRoot, databaseInitialization);
                await WaitForDatabaseAsync(installRoot, temporaryDatabase, TimeSpan.FromSeconds(60));
            }
            finally
            {
                try { File.Delete(databaseInitialization); } catch { }
            }

            progress.Report(new InstallProgress(42, "Creating Laravel production configuration…"));
            WriteLaravelEnvironment(installRoot, localIp, DatabaseUser, databasePassword, appKey, reverbSecret, licenseTrust);
            await ValidatePhpRuntimeAsync(installRoot, progress, 45);
            var releases = PrepareBundledReleases(installRoot);

            var php = Path.Combine(installRoot, "php", "php.exe");
            var server = Path.Combine(installRoot, "server");
            progress.Report(new InstallProgress(50, "Creating the TablePlay database schema…"));
            await RunProcessAsync(php, ["-d", "xdebug.mode=off", "artisan", "migrate", "--force"], server, TimeSpan.FromMinutes(5), progress, 50);
            await RunProcessAsync(php, ["-d", "xdebug.mode=off", "artisan", "db:seed", "--force"], server, TimeSpan.FromMinutes(5), progress, 58);

            progress.Report(new InstallProgress(64, "Creating the administrator and publishing apps…"));
            var bootstrapFile = WriteBootstrapConfiguration(installRoot, options, releases);
            await RunProcessAsync(php,
                ["-d", "xdebug.mode=off", "artisan", "tableplay:bootstrap-installation", bootstrapFile, "--delete-config"],
                server,
                TimeSpan.FromMinutes(5),
                progress,
                64);
            EnsurePublicStorageLink(installRoot);
            await RunProcessAsync(php, ["-d", "xdebug.mode=off", "artisan", "optimize"], server, TimeSpan.FromMinutes(5), progress, 69);

            progress.Report(new InstallProgress(73, "Installing the automatic Windows service…"));
            await StopTemporaryDatabaseAsync(installRoot, temporaryDatabase);
            temporaryDatabase = null;
            InstallWindowsService(installRoot);
            AddPrivateFirewallRules();
            CreateShortcuts(installRoot, localIp);
            RegisterUninstaller(installRoot);

            progress.Report(new InstallProgress(84, "Starting TablePlay services…"));
            RunSystemCommand("sc.exe", ["start", ServiceName], TimeSpan.FromSeconds(30));
            await WaitForHttpAsync($"http://127.0.0.1:{ServerPort}/up", ServiceStartupTimeout, installRoot);
            await ValidateInstalledRuntimeAsync(installRoot, localIp);
            UpdateInstallationVersion(installRoot, ReadPayloadVersion(installRoot), string.Empty);

            progress.Report(new InstallProgress(96, "Verifying the Admin dashboard…"));
            var adminUrl = $"http://{localIp}:{ServerPort}/login";
            var recoveryDetail = recoveredInstallation is null ? string.Empty : $"{Environment.NewLine}Previous interrupted files preserved at: {recoveredInstallation}";
            progress.Report(new InstallProgress(100, "TablePlay is ready.", $"Admin: {adminUrl}{Environment.NewLine}App downloads: http://{localIp}:{ServerPort}/api/v1/app-updates/download/customer-android{recoveryDetail}"));

            File.Delete(Path.Combine(installRoot, ".installation-in-progress"));
            return new InstallResult(adminUrl, localIp, ServerPort);
        }
        catch (Exception installError)
        {
            if (temporaryDatabase is not null)
            {
                try { await StopTemporaryDatabaseAsync(installRoot, temporaryDatabase); }
                catch { try { temporaryDatabase.Kill(entireProcessTree: true); } catch { } }
                temporaryDatabase = null;
            }
            string? failedArchive;
            try { failedArchive = await ArchiveIncompleteInstallationAsync(installRoot); }
            catch (Exception archiveError)
            {
                throw new InvalidOperationException("Installation failed, and TablePlay could not preserve the interrupted files automatically. No cleanup was attempted. Installation error: " + installError.Message + ". Archive error: " + archiveError.Message, archiveError);
            }
            if (failedArchive is not null)
                throw new InvalidOperationException("Installation failed. All interrupted files were preserved at " + failedArchive + ". " + installError.Message, installError);
            throw;
        }
        finally
        {
            if (temporaryDatabase is not null)
            {
                try { temporaryDatabase.Kill(entireProcessTree: true); } catch { }
                temporaryDatabase.Dispose();
            }

            try { Directory.Delete(stagingRoot, recursive: true); } catch { }
        }
    }

    private static async Task<InstallResult> UpgradeAsync(string installRoot, IProgress<InstallProgress> progress)
    {
        ValidateExistingInstallation(installRoot);
        var stagingRoot = Path.Combine(Path.GetTempPath(), "TablePlayUpgrade_" + Guid.NewGuid().ToString("N"));
        var rollbackRoot = Path.Combine(Path.GetTempPath(), "TablePlayRollback_" + Guid.NewGuid().ToString("N"));
        Process? temporaryDatabase = null;
        string? backupPath = null;
        var serviceStopRequested = false;
        var snapshotReady = false;
        var filesMutationStarted = false;
        var databaseMutationStarted = false;
        var serviceWasInstalled = IsServiceInstalled();
        var serviceBinaryWasPresent = File.Exists(Path.Combine(installRoot, "services", "TablePlay.ServiceHost.exe"));
        Directory.CreateDirectory(stagingRoot);
        Directory.CreateDirectory(rollbackRoot);

        try
        {
            progress.Report(new InstallProgress(4, "Validating the upgrade packageâ€¦"));
            ExtractOverlay(stagingRoot);
            var licenseTrust = ValidatePayload(stagingRoot);
            var version = ReadPayloadVersion(stagingRoot);
            RejectDowngrade(installRoot, version);
            progress.Report(new InstallProgress(7, "Closing TablePlay management windows safely…"));
            CloseRunningServerManagers();

            progress.Report(new InstallProgress(10, "Creating a verified pre-upgrade database backupâ€¦"));
            // The backup is taken after the service is quiesced below so no
            // order can be accepted between the dump and file replacement.

            progress.Report(new InstallProgress(18, "Stopping TablePlay services safelyâ€¦"));
            serviceStopRequested = true;
            RunSystemCommand("sc.exe", ["stop", ServiceName], TimeSpan.FromSeconds(45), allowedExitCodes: [0, 1060, 1062]);
            await WaitForServiceStoppedAsync(TimeSpan.FromSeconds(45));
            await WaitForPortClosedAsync("127.0.0.1", ServerPort, TimeSpan.FromSeconds(45));
            await WaitForPortClosedAsync("127.0.0.1", ReverbPort, TimeSpan.FromSeconds(45));
            await WaitForPortClosedAsync("127.0.0.1", DatabasePort, TimeSpan.FromSeconds(45));
            TerminateProcessesUnderRoot(installRoot);

            temporaryDatabase = StartTemporaryDatabase(installRoot);
            await WaitForDatabaseAsync(installRoot, temporaryDatabase, TimeSpan.FromSeconds(60));
            backupPath = await CreateUpgradeBackupAsync(installRoot, version, progress);
            await StopTemporaryDatabaseAsync(installRoot, temporaryDatabase);
            temporaryDatabase = null;

            progress.Report(new InstallProgress(25, "Creating rollback snapshotâ€¦"));
            EnsureRollbackDiskSpace(installRoot);
            SnapshotReplaceableFiles(installRoot, rollbackRoot);
            snapshotReady = true;

            progress.Report(new InstallProgress(38, "Installing TablePlay server version " + version + "â€¦"));
            filesMutationStarted = true;
            ReplaceApplicationFiles(stagingRoot, installRoot, rollbackRoot);
            EnsureLaravelEnvironment(installRoot, licenseTrust);
            ApplyRestaurantLicenseTrust(installRoot, licenseTrust);
            var currentLocalIp = FindLocalIPv4Address();
            UpdateLocalNetworkConfiguration(installRoot, currentLocalIp);
            InstallVisualCppRuntime(installRoot);
            WritePhpConfiguration(installRoot);
            await ValidatePhpRuntimeAsync(installRoot, progress, 48);

            progress.Report(new InstallProgress(52, "Starting the private database for migrationâ€¦"));
            temporaryDatabase = StartTemporaryDatabase(installRoot);
            await WaitForDatabaseAsync(installRoot, temporaryDatabase, TimeSpan.FromSeconds(60));
            var php = Path.Combine(installRoot, "php", "php.exe");
            var server = Path.Combine(installRoot, "server");

            progress.Report(new InstallProgress(61, "Applying database migrationsâ€¦"));
            databaseMutationStarted = true;
            await RunProcessAsync(php, ["-d", "xdebug.mode=off", "artisan", "migrate", "--force"], server, TimeSpan.FromMinutes(10), progress, 61);
            var releases = PrepareBundledReleases(installRoot);
            var config = WriteUpgradeConfiguration(installRoot, releases);
            await RunProcessAsync(php, ["-d", "xdebug.mode=off", "artisan", "tableplay:bootstrap-upgrade", config, "--delete-config"], server, TimeSpan.FromMinutes(5), progress, 69);
            EnsurePublicStorageLink(installRoot);
            await RunProcessAsync(php, ["-d", "xdebug.mode=off", "artisan", "optimize"], server, TimeSpan.FromMinutes(5), progress, 75);

            progress.Report(new InstallProgress(81, "Restarting the upgraded Windows serviceâ€¦"));
            await StopTemporaryDatabaseAsync(installRoot, temporaryDatabase);
            temporaryDatabase = null;
            InstallWindowsService(installRoot);
            AddPrivateFirewallRules();
            CreateShortcuts(installRoot, currentLocalIp);
            RunSystemCommand("sc.exe", ["start", ServiceName], TimeSpan.FromSeconds(30));
            await WaitForHttpAsync($"http://127.0.0.1:{ServerPort}/up", ServiceStartupTimeout, installRoot);
            await ValidateInstalledRuntimeAsync(installRoot, currentLocalIp);
            UpdateInstallationVersion(installRoot, version, backupPath);

            var localIp = FindLocalIPv4Address();
            TryWriteUpgradeReport(installRoot, version, backupPath, true, null);
            progress.Report(new InstallProgress(100, "TablePlay upgrade completed.", $"Version {version}{Environment.NewLine}Backup: {backupPath}{Environment.NewLine}Health check: passed"));
            return new InstallResult($"http://{localIp}:{ServerPort}/login", localIp, ServerPort);
        }
        catch (Exception exception)
        {
            progress.Report(new InstallProgress(86, "Upgrade failed. Rolling back files and databaseâ€¦", exception.Message));
            try
            {
                if (temporaryDatabase is not null) { await StopTemporaryDatabaseAsync(installRoot, temporaryDatabase); temporaryDatabase = null; }
                if (filesMutationStarted && snapshotReady)
                {
                    RunSystemCommand("sc.exe", ["stop", ServiceName], TimeSpan.FromSeconds(45), allowedExitCodes: [0, 1060, 1062]);
                    await WaitForServiceStoppedAsync(TimeSpan.FromSeconds(45));
                    await WaitForPortClosedAsync("127.0.0.1", ServerPort, TimeSpan.FromSeconds(45));
                    await WaitForPortClosedAsync("127.0.0.1", ReverbPort, TimeSpan.FromSeconds(45));
                    await WaitForPortClosedAsync("127.0.0.1", DatabasePort, TimeSpan.FromSeconds(45));
                    TerminateProcessesUnderRoot(installRoot);
                    RestoreReplaceableFiles(rollbackRoot, installRoot);
                    WritePhpConfiguration(installRoot);
                }
                if (databaseMutationStarted && backupPath is not null)
                {
                    temporaryDatabase = StartTemporaryDatabase(installRoot);
                    await WaitForDatabaseAsync(installRoot, temporaryDatabase, TimeSpan.FromSeconds(60));
                    await RestoreUpgradeBackupAsync(installRoot, backupPath, progress);
                    await StopTemporaryDatabaseAsync(installRoot, temporaryDatabase);
                    temporaryDatabase = null;
                }
                if (filesMutationStarted && snapshotReady)
                {
                    EnsurePublicStorageLink(installRoot);
                    UpdateLocalNetworkConfiguration(installRoot, FindLocalIPv4Address());
                }
                if (serviceStopRequested)
                {
                    if (serviceWasInstalled && serviceBinaryWasPresent)
                    {
                        if (filesMutationStarted && snapshotReady) InstallWindowsService(installRoot);
                        RunSystemCommand("sc.exe", ["start", ServiceName], TimeSpan.FromSeconds(30), allowedExitCodes: [0, 1056]);
                        await WaitForHttpAsync($"http://127.0.0.1:{ServerPort}/up", ServiceStartupTimeout, installRoot);
                        ValidateRollbackRuntime(installRoot);
                    }
                    else if (!serviceWasInstalled)
                    {
                        RunSystemCommand("sc.exe", ["delete", ServiceName], TimeSpan.FromSeconds(30), allowedExitCodes: [0, 1060, 1072]);
                    }
                }
                TryWriteUpgradeReport(installRoot, "rollback", backupPath, false, exception.Message);
            }
            catch (Exception rollbackError)
            {
                throw new InvalidOperationException("Upgrade failed and automatic rollback also failed. Data backup: " + backupPath + ". Upgrade error: " + exception.Message + ". Rollback error: " + rollbackError.Message, rollbackError);
            }
            throw new InvalidOperationException("Upgrade failed and TablePlay was rolled back safely. Backup: " + backupPath + ". " + exception.Message, exception);
        }
        finally
        {
            if (temporaryDatabase is not null) { try { temporaryDatabase.Kill(entireProcessTree: true); } catch { } temporaryDatabase.Dispose(); }
            try { Directory.Delete(stagingRoot, true); } catch { }
            try { Directory.Delete(rollbackRoot, true); } catch { }
            if (serviceStopRequested && serviceWasInstalled && serviceBinaryWasPresent && !IsServiceRunning())
            {
                try { RunSystemCommand("sc.exe", ["start", ServiceName], TimeSpan.FromSeconds(30), allowedExitCodes: [0, 1056]); } catch { }
            }
        }
    }

    private static void ValidateExistingInstallation(string root)
    {
        foreach (var path in new[] { "database", "config" })
            if (!Directory.Exists(Path.Combine(root, path))) throw new InvalidOperationException("Existing TablePlay installation is incomplete: missing " + path + ".");
        foreach (var path in new[] { Path.Combine("config", "database-client.ini"), Path.Combine("database", "my.ini") })
            if (!File.Exists(Path.Combine(root, path))) throw new InvalidOperationException("Existing TablePlay configuration is incomplete: missing " + path + ".");
    }

    internal static InstallationState GetInstallationState(string root)
    {
        if (!Directory.Exists(root) || !Directory.EnumerateFileSystemEntries(root).Any()) return InstallationState.Fresh;
        if (!File.Exists(Path.Combine(root, ".installation-in-progress"))) return InstallationState.Repair;

        if (InstallationMetadataHasProductVersion(Path.Combine(root, "config", "installation.json"))) return InstallationState.Repair;
        if (DirectoryHasEntries(Path.Combine(root, "server", "storage", "app", "public"))) return InstallationState.Repair;
        if (DirectoryHasEntries(Path.Combine(root, "backups"))) return InstallationState.Repair;
        return InstallationState.IncompleteFresh;
    }

    private static bool DirectoryHasEntries(string path) =>
        Directory.Exists(path) && Directory.EnumerateFileSystemEntries(path).Any();

    private static bool InstallationMetadataHasProductVersion(string path)
    {
        if (!File.Exists(path)) return false;
        try
        {
            using var document = JsonDocument.Parse(File.ReadAllText(path));
            return document.RootElement.TryGetProperty("ProductVersion", out var version)
                && !string.IsNullOrWhiteSpace(version.GetString());
        }
        catch (JsonException) { return false; }
    }

    private static async Task<string?> ArchiveIncompleteInstallationAsync(string root)
    {
        if (!Directory.Exists(root)) return null;
        var expected = Path.GetFullPath(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "TablePlay"));
        if (!Path.GetFullPath(root).Equals(expected, StringComparison.OrdinalIgnoreCase))
            throw new InvalidOperationException("Refusing to archive an unexpected installation path: " + root);

        try { RunSystemCommand("sc.exe", ["stop", ServiceName], TimeSpan.FromSeconds(30), allowedExitCodes: [0, 1060, 1062]); } catch { }
        try { await WaitForServiceStoppedAsync(TimeSpan.FromSeconds(30)); } catch { }
        TerminateProcessesUnderRoot(root);
        try { RunSystemCommand("sc.exe", ["delete", ServiceName], TimeSpan.FromSeconds(30), allowedExitCodes: [0, 1060, 1072]); } catch { }
        try { RunSystemCommand("netsh.exe", ["advfirewall", "firewall", "delete", "rule", "name=TablePlay Web"], TimeSpan.FromSeconds(15), allowedExitCodes: [0, 1]); } catch { }
        try { RunSystemCommand("netsh.exe", ["advfirewall", "firewall", "delete", "rule", "name=TablePlay Realtime"], TimeSpan.FromSeconds(15), allowedExitCodes: [0, 1]); } catch { }
        try { Registry.LocalMachine.DeleteSubKeyTree(@"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\TablePlay", false); } catch { }

        var recoveryRoot = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "TablePlay", "Recovery");
        return MoveDirectoryToRecovery(root, recoveryRoot);
    }

    internal static string MoveDirectoryToRecovery(string root, string recoveryRoot)
    {
        Directory.CreateDirectory(recoveryRoot);
        var destination = Path.Combine(recoveryRoot, "interrupted-" + DateTime.Now.ToString("yyyyMMdd-HHmmss") + "-" + Guid.NewGuid().ToString("N")[..8]);
        Directory.Move(root, destination);
        File.WriteAllText(Path.Combine(destination, "RECOVERY.txt"),
            "TablePlay preserved this interrupted installation instead of deleting it." + Environment.NewLine
            + "Preserved at: " + DateTimeOffset.Now.ToString("O") + Environment.NewLine,
            new UTF8Encoding(false));
        return destination;
    }

    private static void RejectDowngrade(string root, string payloadVersion)
    {
        var path = Path.Combine(root, "config", "installation.json");
        if (!File.Exists(path)) return;
        try
        {
            using var document = JsonDocument.Parse(File.ReadAllText(path));
            if (!document.RootElement.TryGetProperty("ProductVersion", out var existingValue)) return;
            if (Version.TryParse(existingValue.GetString(), out var existing)
                && Version.TryParse(payloadVersion, out var candidate)
                && candidate < existing)
                throw new InvalidOperationException($"TablePlay {payloadVersion} cannot downgrade the installed version {existing}. Use a newer installer.");
        }
        catch (JsonException) { }
    }

    private static void CloseRunningServerManagers()
    {
        foreach (var process in Process.GetProcessesByName("TablePlay.ServerManager"))
        {
            using (process)
            {
                try
                {
                    if (process.Id == Environment.ProcessId) continue;
                    process.CloseMainWindow();
                    if (!process.WaitForExit(5000))
                    {
                        process.Kill(entireProcessTree: true);
                        process.WaitForExit(5000);
                    }
                }
                catch (InvalidOperationException) { }
            }
        }
    }

    private static string ReadPayloadVersion(string stagingRoot)
    {
        using var manifest = JsonDocument.Parse(File.ReadAllText(Path.Combine(stagingRoot, "payload-manifest.json")));
        return manifest.RootElement.GetProperty("installer_version").GetString() ?? throw new InvalidDataException("Installer version is missing.");
    }

    private static async Task<string> CreateUpgradeBackupAsync(string root, string version, IProgress<InstallProgress> progress)
    {
        var directory = Path.Combine(root, "backups"); Directory.CreateDirectory(directory);
        var path = Path.Combine(directory, $"tableplay-pre-upgrade-{version}-{DateTime.Now:yyyyMMdd-HHmmss}.sql");
        await RunProcessAsync(Path.Combine(root, "database", "bin", "mysqldump.exe"),
            [$"--defaults-extra-file={Path.Combine(root, "config", "database-client.ini")}", "--single-transaction", "--routines", "--events", "--triggers", "--hex-blob", $"--result-file={path}", "tableplay"],
            Path.Combine(root, "database", "bin"), TimeSpan.FromMinutes(15), progress, 12);
        if (!File.Exists(path) || new FileInfo(path).Length == 0) throw new InvalidOperationException("Pre-upgrade backup verification failed.");
        File.WriteAllText(path + ".sha256", Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(path))).ToLowerInvariant());
        return path;
    }

    private static readonly string[] ReplaceableDirectories = ["server", "php", "services", "server-manager", "uninstaller", "packages", "prerequisites"];
    private static void SnapshotReplaceableFiles(string root, string snapshot)
    {
        foreach (var name in ReplaceableDirectories) { var source = Path.Combine(root, name); if (Directory.Exists(source)) CopyDirectory(source, Path.Combine(snapshot, name)); }
        foreach (var name in new[] { "payload-manifest.json" }) if (File.Exists(Path.Combine(root, name))) File.Copy(Path.Combine(root, name), Path.Combine(snapshot, name), true);
    }

    private static void ReplaceApplicationFiles(string staging, string root, string snapshot)
    {
        foreach (var name in ReplaceableDirectories)
        {
            var target = Path.Combine(root, name);
            DeleteDirectorySafely(target);
            CopyDirectory(Path.Combine(staging, name), target);
        }
        File.Copy(Path.Combine(staging, "payload-manifest.json"), Path.Combine(root, "payload-manifest.json"), true);

        var snapshotServer = Path.Combine(snapshot, "server");
        var snapshotEnvironment = Path.Combine(snapshotServer, ".env");
        if (File.Exists(snapshotEnvironment)) File.Copy(snapshotEnvironment, Path.Combine(root, "server", ".env"), true);
        var snapshotStorage = Path.Combine(snapshotServer, "storage");
        if (Directory.Exists(snapshotStorage))
        {
            var storage = Path.Combine(root, "server", "storage");
            DeleteDirectorySafely(storage);
            CopyDirectory(snapshotStorage, storage);
        }
    }

    private static void EnsureRollbackDiskSpace(string root)
    {
        long required = 512L * 1024 * 1024;
        foreach (var name in ReplaceableDirectories)
        {
            var path = Path.Combine(root, name);
            if (Directory.Exists(path)) required += CalculateDirectorySize(path);
        }
        var tempRoot = Path.GetPathRoot(Path.GetTempPath()) ?? throw new IOException("Cannot determine the temporary drive.");
        var drive = new DriveInfo(tempRoot);
        if (drive.AvailableFreeSpace < required)
            throw new IOException($"TablePlay needs at least {Math.Ceiling(required / 1024d / 1024d)} MB free on {drive.Name} for a verified rollback snapshot, but only {Math.Floor(drive.AvailableFreeSpace / 1024d / 1024d)} MB is available.");
    }

    private static long CalculateDirectorySize(string path)
    {
        long total = 0;
        foreach (var file in Directory.EnumerateFiles(path))
            if ((File.GetAttributes(file) & FileAttributes.ReparsePoint) == 0) total += new FileInfo(file).Length;
        foreach (var directory in Directory.EnumerateDirectories(path))
        {
            var info = new DirectoryInfo(directory);
            if ((info.Attributes & FileAttributes.ReparsePoint) == 0) total += CalculateDirectorySize(directory);
        }
        return total;
    }

    private static void EnsureLaravelEnvironment(string root, LicenseTrustConfiguration licenseTrust)
    {
        var environment = Path.Combine(root, "server", ".env");
        if (File.Exists(environment)) return;

        var lines = File.ReadAllLines(Path.Combine(root, "config", "database-client.ini"));
        var passwordLine = lines.FirstOrDefault(line => line.TrimStart().StartsWith("password=", StringComparison.OrdinalIgnoreCase))
            ?? throw new InvalidDataException("The preserved database password could not be recovered for repair.");
        var databasePassword = passwordLine[(passwordLine.IndexOf('=') + 1)..].Trim();
        var userLine = lines.FirstOrDefault(line => line.TrimStart().StartsWith("user=", StringComparison.OrdinalIgnoreCase));
        var databaseUser = userLine is null ? "root" : userLine[(userLine.IndexOf('=') + 1)..].Trim();
        if (string.IsNullOrWhiteSpace(databaseUser)) databaseUser = "root";
        var localIp = FindLocalIPv4Address();
        var installationPath = Path.Combine(root, "config", "installation.json");
        if (File.Exists(installationPath))
        {
            try
            {
                using var installation = JsonDocument.Parse(File.ReadAllText(installationPath));
                if (installation.RootElement.TryGetProperty("LocalIp", out var storedIp) && !string.IsNullOrWhiteSpace(storedIp.GetString())) localIp = storedIp.GetString()!;
            }
            catch (JsonException) { }
        }

        WriteLaravelEnvironment(root, localIp, databaseUser, databasePassword,
            "base64:" + Convert.ToBase64String(RandomNumberGenerator.GetBytes(32)), RandomToken(48), licenseTrust);
    }

    private static void UpdateLocalNetworkConfiguration(string root, string localIp)
    {
        var path = Path.Combine(root, "server", ".env");
        if (!File.Exists(path)) throw new InvalidDataException("The TablePlay production environment is missing.");
        var replacements = new Dictionary<string, string>(StringComparer.Ordinal)
        {
            ["APP_URL"] = $"http://{localIp}:{ServerPort}",
            ["REVERB_HOST"] = localIp,
            ["REVERB_PORT"] = ReverbPort.ToString(),
            ["VITE_REVERB_HOST"] = localIp,
            ["VITE_REVERB_PORT"] = ReverbPort.ToString(),
        };
        var seen = new HashSet<string>(StringComparer.Ordinal);
        var lines = File.ReadAllLines(path).Select(line =>
        {
            var separator = line.IndexOf('=');
            if (separator <= 0) return line;
            var key = line[..separator].Trim();
            if (!replacements.TryGetValue(key, out var value)) return line;
            seen.Add(key);
            return key + "=" + value;
        }).ToList();
        foreach (var replacement in replacements.Where(item => !seen.Contains(item.Key)))
            lines.Add(replacement.Key + "=" + replacement.Value);
        File.WriteAllLines(path, lines, new UTF8Encoding(false));
    }

    private static void RestoreReplaceableFiles(string snapshot, string root)
    {
        foreach (var name in ReplaceableDirectories) { var target = Path.Combine(root, name); DeleteDirectorySafely(target); var source = Path.Combine(snapshot, name); if (Directory.Exists(source)) CopyDirectory(source, target); }
        if (File.Exists(Path.Combine(snapshot, "payload-manifest.json"))) File.Copy(Path.Combine(snapshot, "payload-manifest.json"), Path.Combine(root, "payload-manifest.json"), true);
    }

    private static string WriteUpgradeConfiguration(string root, List<Dictionary<string, object?>> releases)
    {
        var path = Path.Combine(root, "config", "installer-upgrade.json");
        File.WriteAllText(path, JsonSerializer.Serialize(new { releases }, JsonOptions)); return path;
    }

    private static async Task RestoreUpgradeBackupAsync(string root, string backup, IProgress<InstallProgress> progress)
    {
        var checksumPath = backup + ".sha256";
        if (!File.Exists(backup) || !File.Exists(checksumPath))
            throw new InvalidDataException("The rollback backup or its checksum is missing; the current database was not changed.");
        var expectedChecksum = File.ReadAllText(checksumPath).Trim();
        await using (var backupStream = File.OpenRead(backup))
        {
            var actualChecksum = Convert.ToHexString(await SHA256.HashDataAsync(backupStream)).ToLowerInvariant();
            if (!actualChecksum.Equals(expectedChecksum, StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("The rollback backup checksum is invalid; the current database was not changed.");
        }

        var mysql = Path.Combine(root, "database", "bin", "mysql.exe");
        var defaults = $"--defaults-extra-file={Path.Combine(root, "config", "database-client.ini")}";
        await RunProcessAsync(mysql, [defaults, "--execute=DROP DATABASE IF EXISTS tableplay; CREATE DATABASE tableplay CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"], Path.GetDirectoryName(mysql)!, TimeSpan.FromMinutes(2), progress, 90);
        var start = new ProcessStartInfo(mysql) { WorkingDirectory = Path.GetDirectoryName(mysql)!, UseShellExecute = false, CreateNoWindow = true, RedirectStandardInput = true, RedirectStandardError = true };
        start.ArgumentList.Add(defaults); start.ArgumentList.Add("tableplay");
        using var process = Process.Start(start) ?? throw new InvalidOperationException("Could not start database rollback.");
        await using (var file = File.OpenRead(backup)) await file.CopyToAsync(process.StandardInput.BaseStream);
        process.StandardInput.Close();
        try
        {
            await process.WaitForExitAsync().WaitAsync(TimeSpan.FromMinutes(30));
        }
        catch (TimeoutException)
        {
            process.Kill(entireProcessTree: true);
            await process.WaitForExitAsync();
            throw new TimeoutException("Database rollback exceeded 30 minutes and was stopped safely.");
        }
        if (process.ExitCode != 0) throw new InvalidOperationException("Database rollback failed: " + await process.StandardError.ReadToEndAsync());
    }

    private static void UpdateInstallationVersion(string root, string version, string backup)
    {
        var path = Path.Combine(root, "config", "installation.json");
        JsonObject node;
        try { node = File.Exists(path) ? JsonNode.Parse(File.ReadAllText(path))?.AsObject() ?? new JsonObject() : new JsonObject(); }
        catch (JsonException) { node = new JsonObject(); }
        node["ServerPort"] ??= ServerPort;
        node["ReverbPort"] ??= ReverbPort;
        node["DatabasePort"] ??= DatabasePort;
        node["LocalIp"] = FindLocalIPv4Address();
        node["ProductVersion"] = version;
        node["LastUpgradeAt"] = DateTimeOffset.Now;
        node["LastUpgradeBackup"] = backup;
        try
        {
            File.WriteAllText(path, node.ToJsonString(JsonOptions));
            using var key = Registry.LocalMachine.CreateSubKey(@"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\TablePlay", writable: true);
            key?.SetValue("DisplayVersion", version);
        }
        catch (Exception exception)
        {
            try { File.AppendAllText(Path.Combine(root, "logs", "setup-warnings.log"), $"[{DateTimeOffset.Now:O}] Could not update installation metadata: {exception.Message}{Environment.NewLine}"); } catch { }
        }
    }

    private static void TryWriteUpgradeReport(string root, string version, string? backup, bool success, string? error)
    {
        try
        {
            Directory.CreateDirectory(Path.Combine(root, "logs"));
            File.WriteAllText(Path.Combine(root, "logs", "last-upgrade.json"), JsonSerializer.Serialize(new { version, backup, success, error, completed_at = DateTimeOffset.Now }, JsonOptions));
        }
        catch { }
    }

    private static bool IsServiceRunning()
    {
        try { var result = RunSystemCommand("sc.exe", ["query", ServiceName], TimeSpan.FromSeconds(10), allowedExitCodes: [0, 1060], captureOutput: true); return result.Contains("RUNNING", StringComparison.OrdinalIgnoreCase); }
        catch { return false; }
    }

    private static bool IsServiceInstalled()
    {
        try
        {
            var result = RunSystemCommand("sc.exe", ["query", ServiceName], TimeSpan.FromSeconds(10), allowedExitCodes: [0, 1060], captureOutput: true);
            return result.Contains("SERVICE_NAME", StringComparison.OrdinalIgnoreCase);
        }
        catch { return false; }
    }

    private static void TerminateProcessesUnderRoot(string root)
    {
        var expected = Path.GetFullPath(root).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
        var failures = new List<string>();
        foreach (var process in Process.GetProcesses())
        {
            using (process)
            {
                string? executable;
                try { executable = process.MainModule?.FileName; }
                catch { continue; }
                if (executable is null || !Path.GetFullPath(executable).StartsWith(expected, StringComparison.OrdinalIgnoreCase)) continue;
                try
                {
                    process.Kill(entireProcessTree: true);
                    if (!process.WaitForExit(15000)) failures.Add($"{process.ProcessName} ({process.Id})");
                }
                catch (Exception exception) { failures.Add($"{process.ProcessName} ({process.Id}): {exception.Message}"); }
            }
        }
        if (failures.Count > 0)
            throw new IOException("TablePlay could not stop these application processes: " + string.Join(", ", failures) + ". Restart Windows and run setup again.");
    }

    private static async Task WaitForPortClosedAsync(string host, int port, TimeSpan timeout)
    {
        var deadline = DateTimeOffset.UtcNow + timeout;
        while (DateTimeOffset.UtcNow < deadline)
        {
            try { using var client = new TcpClient(); await client.ConnectAsync(host, port).WaitAsync(TimeSpan.FromSeconds(1)); }
            catch { return; }
            await Task.Delay(500);
        }
        throw new TimeoutException("The existing TablePlay database did not stop in time.");
    }

    private static async Task WaitForServiceStoppedAsync(TimeSpan timeout)
    {
        var deadline = DateTimeOffset.UtcNow + timeout;
        while (DateTimeOffset.UtcNow < deadline)
        {
            var state = RunSystemCommand(
                "sc.exe",
                ["query", ServiceName],
                TimeSpan.FromSeconds(10),
                allowedExitCodes: [0, 1060],
                captureOutput: true);
            if (!state.Contains("RUNNING", StringComparison.OrdinalIgnoreCase)
                && !state.Contains("STOP_PENDING", StringComparison.OrdinalIgnoreCase)) return;
            await Task.Delay(500);
        }
        throw new TimeoutException("The existing TablePlay Windows service did not stop in time.");
    }

    private static void ExtractOverlay(string destination)
    {
        var executable = Environment.ProcessPath ?? throw new InvalidOperationException("Cannot locate the setup executable.");
        using var stream = File.OpenRead(executable);
        var footerLength = sizeof(long) + OverlayMagic.Length;
        if (stream.Length <= footerLength)
        {
            throw new InvalidDataException("This setup executable does not contain a TablePlay payload.");
        }

        stream.Seek(-footerLength, SeekOrigin.End);
        using var reader = new BinaryReader(stream, Encoding.UTF8, leaveOpen: true);
        var payloadLength = reader.ReadInt64();
        var magic = reader.ReadBytes(OverlayMagic.Length);
        if (!magic.SequenceEqual(OverlayMagic) || payloadLength <= 0 || payloadLength > stream.Length - footerLength)
        {
            throw new InvalidDataException("The TablePlay installer payload is missing or invalid.");
        }

        var payloadStart = stream.Length - footerLength - payloadLength;
        stream.Seek(payloadStart, SeekOrigin.Begin);
        using var bounded = new BoundedStream(stream, payloadLength);
        using var archive = new ZipArchive(bounded, ZipArchiveMode.Read, leaveOpen: false);
        var destinationRoot = Path.GetFullPath(destination) + Path.DirectorySeparatorChar;

        foreach (var entry in archive.Entries)
        {
            var target = Path.GetFullPath(Path.Combine(destination, entry.FullName));
            if (!target.StartsWith(destinationRoot, StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidDataException("The installer contains an unsafe archive path.");
            }

            if (string.IsNullOrEmpty(entry.Name))
            {
                Directory.CreateDirectory(target);
                continue;
            }

            Directory.CreateDirectory(Path.GetDirectoryName(target)!);
            entry.ExtractToFile(target, overwrite: true);
        }
    }

    internal static LicenseTrustConfiguration ValidatePayload(string stagingRoot)
    {
        var required = new[]
        {
            "payload-manifest.json",
            Path.Combine("server", "artisan"),
            Path.Combine("server", "vendor", "autoload.php"),
            Path.Combine("php", "php.exe"),
            Path.Combine("prerequisites", "vc_redist.x64.exe"),
            Path.Combine("database", "bin", "mysqld.exe"),
            Path.Combine("database", "bin", "mysql_install_db.exe"),
            Path.Combine("services", "TablePlay.ServiceHost.exe"),
            Path.Combine("server-manager", "TablePlay.ServerManager.exe"),
            Path.Combine("packages", "staff-android.apk"),
            Path.Combine("packages", "customer-android.apk"),
            Path.Combine("packages", "staff-windows.zip"),
        };

        foreach (var relativePath in required)
        {
            if (!File.Exists(Path.Combine(stagingRoot, relativePath)))
            {
                throw new InvalidDataException("The installer payload is missing " + relativePath + ".");
            }
        }

        using var manifest = JsonDocument.Parse(File.ReadAllText(Path.Combine(stagingRoot, "payload-manifest.json")));
        var licenseTrust = ReadLicenseTrustConfiguration(manifest.RootElement);
        if (!manifest.RootElement.TryGetProperty("payload_files", out var payloadFiles) || payloadFiles.ValueKind != JsonValueKind.Array)
            throw new InvalidDataException("The installer payload does not include its integrity manifest.");
        foreach (var entry in payloadFiles.EnumerateArray())
        {
            var relative = entry.GetProperty("relative_path").GetString()?.Replace('/', Path.DirectorySeparatorChar)
                ?? throw new InvalidDataException("A payload integrity entry has no path.");
            if (Path.IsPathRooted(relative) || relative.Split(Path.DirectorySeparatorChar).Contains(".."))
                throw new InvalidDataException("The payload integrity manifest contains an unsafe path.");
            var path = Path.GetFullPath(Path.Combine(stagingRoot, relative));
            var stagingPrefix = Path.GetFullPath(stagingRoot).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
            if (!path.StartsWith(stagingPrefix, StringComparison.OrdinalIgnoreCase) || !File.Exists(path))
                throw new InvalidDataException("The verified installer payload is missing " + relative + ".");
            var expectedLength = entry.GetProperty("length").GetInt64();
            var expectedHash = entry.GetProperty("sha256").GetString();
            if (new FileInfo(path).Length != expectedLength)
                throw new InvalidDataException("The installer payload length check failed for " + relative + ".");
            using var stream = File.OpenRead(path);
            var actualHash = Convert.ToHexString(SHA256.HashData(stream)).ToLowerInvariant();
            if (!actualHash.Equals(expectedHash, StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("The installer payload checksum failed for " + relative + ".");
        }

        return licenseTrust;
    }

    private static LicenseTrustConfiguration ReadLicenseTrustConfiguration(JsonElement manifest)
    {
        if (ContainsForbiddenLicenseSecret(manifest))
            throw new InvalidDataException("The installer manifest contains a forbidden private or secret licensing value.");
        if (!manifest.TryGetProperty("licensing", out var licensing) || licensing.ValueKind != JsonValueKind.Object)
            throw new InvalidDataException("The installer payload does not contain its Ed25519 public-key trust configuration.");

        var algorithm = licensing.TryGetProperty("algorithm", out var algorithmNode) ? algorithmNode.GetString() : null;
        var keyId = licensing.TryGetProperty("key_id", out var keyIdNode) ? keyIdNode.GetString() : null;
        var encodedPublicKey = licensing.TryGetProperty("public_key", out var publicKeyNode) ? publicKeyNode.GetString() : null;
        var expectedFingerprint = licensing.TryGetProperty("public_key_sha256", out var fingerprintNode) ? fingerprintNode.GetString() : null;
        if (!string.Equals(algorithm, "Ed25519", StringComparison.Ordinal))
            throw new InvalidDataException("The installer licensing algorithm must be Ed25519.");
        if (!IsValidLicenseKeyId(keyId))
            throw new InvalidDataException("The installer licensing key_id is missing or invalid.");

        byte[] publicKey;
        try { publicKey = Convert.FromBase64String(encodedPublicKey ?? string.Empty); }
        catch (FormatException exception) { throw new InvalidDataException("The installer Ed25519 public key is not valid Base64.", exception); }
        if (publicKey.Length != 32)
            throw new InvalidDataException($"The installer Ed25519 public key must decode to exactly 32 bytes; received {publicKey.Length}.");

        var normalizedPublicKey = Convert.ToBase64String(publicKey);
        var actualFingerprint = Convert.ToHexString(SHA256.HashData(publicKey)).ToLowerInvariant();
        if (string.IsNullOrWhiteSpace(expectedFingerprint)
            || !actualFingerprint.Equals(expectedFingerprint, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("The installer Ed25519 public-key fingerprint is missing or invalid.");

        var trustedPublicKeys = new SortedDictionary<string, string>(StringComparer.Ordinal);
        if (licensing.TryGetProperty("trusted_public_keys", out var trustedNode))
        {
            if (trustedNode.ValueKind != JsonValueKind.Object)
                throw new InvalidDataException("The installer trusted_public_keys value must be an object keyed by license key ID.");
            foreach (var property in trustedNode.EnumerateObject())
            {
                if (trustedPublicKeys.Count >= 64)
                    throw new InvalidDataException("The installer cannot contain more than 64 trusted Ed25519 public keys.");
                if (!IsValidLicenseKeyId(property.Name) || property.Value.ValueKind != JsonValueKind.String)
                    throw new InvalidDataException("The installer contains an invalid trusted Ed25519 key entry.");
                var trustedPublicKey = NormalizeEd25519PublicKey(
                    property.Value.GetString(),
                    $"The trusted Ed25519 public key '{property.Name}'");
                if (!trustedPublicKeys.TryAdd(property.Name, trustedPublicKey))
                    throw new InvalidDataException($"The installer contains the trusted Ed25519 key ID '{property.Name}' more than once.");
            }
        }
        if (trustedPublicKeys.TryGetValue(keyId!, out var configuredCurrent)
            && !string.Equals(configuredCurrent, normalizedPublicKey, StringComparison.Ordinal))
            throw new InvalidDataException("The current installer public key conflicts with the same key ID in trusted_public_keys.");
        if (!trustedPublicKeys.ContainsKey(keyId!) && trustedPublicKeys.Count >= 64)
            throw new InvalidDataException("The installer cannot add its current key because trusted_public_keys already contains 64 historical keys.");
        trustedPublicKeys[keyId!] = normalizedPublicKey;

        return new LicenseTrustConfiguration("Ed25519", keyId!, normalizedPublicKey, actualFingerprint, trustedPublicKeys);
    }

    private static bool IsValidLicenseKeyId(string? keyId) =>
        !string.IsNullOrWhiteSpace(keyId)
        && keyId.Length <= 80
        && char.IsAsciiLetterOrDigit(keyId[0])
        && keyId.All(character => char.IsAsciiLetterOrDigit(character) || character is '.' or '_' or '-');

    private static string NormalizeEd25519PublicKey(string? encodedPublicKey, string description)
    {
        byte[] publicKey;
        try { publicKey = Convert.FromBase64String(encodedPublicKey ?? string.Empty); }
        catch (FormatException exception) { throw new InvalidDataException(description + " is not valid Base64.", exception); }
        if (publicKey.Length != 32)
            throw new InvalidDataException(description + $" must decode to exactly 32 bytes; received {publicKey.Length}.");
        return Convert.ToBase64String(publicKey);
    }

    private static bool ContainsForbiddenLicenseSecret(JsonElement node)
    {
        if (node.ValueKind == JsonValueKind.Object)
        {
            foreach (var property in node.EnumerateObject())
            {
                if (property.Name.Contains("private", StringComparison.OrdinalIgnoreCase)
                    || property.Name.Contains("secret", StringComparison.OrdinalIgnoreCase)) return true;
                if (ContainsForbiddenLicenseSecret(property.Value)) return true;
            }
        }
        else if (node.ValueKind == JsonValueKind.Array)
        {
            foreach (var item in node.EnumerateArray())
                if (ContainsForbiddenLicenseSecret(item)) return true;
        }
        return false;
    }

    private static async Task InitializeDatabaseAsync(string root, string password, IProgress<InstallProgress> progress, int databasePort = DatabasePort)
    {
        var database = Path.Combine(root, "database");
        var data = Path.Combine(database, "data");
        Directory.CreateDirectory(data);
        await RunProcessAsync(
            Path.Combine(database, "bin", "mysql_install_db.exe"),
            [$"--datadir={data}", $"--password={password}", $"--port={databasePort}", "--silent"],
            database,
            TimeSpan.FromMinutes(5),
            progress,
            27,
            includeOutput: false);
    }

    private static void InstallVisualCppRuntime(string root)
    {
        var redistributable = Path.Combine(root, "prerequisites", "vc_redist.x64.exe");
        try
        {
            RunSystemCommand(
                redistributable,
                ["/install", "/quiet", "/norestart"],
                TimeSpan.FromMinutes(5),
                allowedExitCodes: [0, 1638, 3010]);
        }
        catch (Exception exception)
        {
            throw new InvalidOperationException(
                "The required Microsoft Visual C++ runtime could not be installed. Restart Windows, run TablePlay Setup as Administrator, and try again. "
                + exception.Message,
                exception);
        }
    }

    private static Process StartTemporaryDatabase(string root, string? initializationFile = null)
    {
        var executable = Path.Combine(root, "database", "bin", "mysqld.exe");
        var process = new Process
        {
            StartInfo = new ProcessStartInfo
            {
                FileName = executable,
                WorkingDirectory = Path.GetDirectoryName(executable)!,
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                ArgumentList = { $"--defaults-file={Path.Combine(root, "database", "my.ini")}", "--console" },
            },
        };
        if (!string.IsNullOrWhiteSpace(initializationFile))
        {
            var fullInitializationPath = Path.GetFullPath(initializationFile);
            var configPrefix = Path.GetFullPath(Path.Combine(root, "config")).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
            if (!fullInitializationPath.StartsWith(configPrefix, StringComparison.OrdinalIgnoreCase) || !File.Exists(fullInitializationPath))
                throw new InvalidDataException("The private database initialization file is missing or outside TablePlay configuration.");
            process.StartInfo.ArgumentList.Add($"--init-file={fullInitializationPath}");
        }
        var logPath = Path.Combine(root, "logs", "setup-database.log");
        Directory.CreateDirectory(Path.GetDirectoryName(logPath)!);
        process.OutputDataReceived += (_, eventArgs) => { if (eventArgs.Data is not null) AppendSetupLog(logPath, eventArgs.Data); };
        process.ErrorDataReceived += (_, eventArgs) => { if (eventArgs.Data is not null) AppendSetupLog(logPath, eventArgs.Data); };
        process.Start();
        process.BeginOutputReadLine();
        process.BeginErrorReadLine();
        return process;
    }

    private static readonly object SetupLogLock = new();

    private static void AppendSetupLog(string path, string message)
    {
        lock (SetupLogLock) File.AppendAllText(path, $"[{DateTimeOffset.Now:O}] {message}{Environment.NewLine}");
    }

    private static async Task WaitForDatabaseAsync(string root, Process process, TimeSpan timeout, int databasePort = DatabasePort)
    {
        var deadline = DateTimeOffset.UtcNow + timeout;
        while (DateTimeOffset.UtcNow < deadline)
        {
            if (process.HasExited)
            {
                var log = ReadLastLines(Path.Combine(root, "logs", "setup-database.log"), 30);
                throw new InvalidOperationException($"The private database exited with code {process.ExitCode} before becoming ready.{Environment.NewLine}{log}");
            }
            try
            {
                using var client = new TcpClient();
                await client.ConnectAsync("127.0.0.1", databasePort).WaitAsync(TimeSpan.FromSeconds(2));
                RunSystemCommand(
                    Path.Combine(root, "database", "bin", "mysql.exe"),
                    [$"--defaults-extra-file={Path.Combine(root, "config", "database-client.ini")}", "--batch", "--skip-column-names", "--execute=SELECT 1;"],
                    TimeSpan.FromSeconds(10));
                return;
            }
            catch when (!process.HasExited)
            {
                await Task.Delay(500);
            }
        }
        var diagnostics = ReadLastLines(Path.Combine(root, "logs", "setup-database.log"), 30);
        throw new TimeoutException($"The private TablePlay database did not become ready within {timeout.TotalSeconds:0} seconds.{Environment.NewLine}{diagnostics}");
    }

    private static string ReadLastLines(string path, int count)
    {
        try { return File.Exists(path) ? string.Join(Environment.NewLine, File.ReadLines(path).TakeLast(count)) : string.Empty; }
        catch { return string.Empty; }
    }

    private static async Task StopTemporaryDatabaseAsync(string root, Process process)
    {
        try
        {
            await RunProcessAsync(
                Path.Combine(root, "database", "bin", "mysqladmin.exe"),
                [$"--defaults-extra-file={Path.Combine(root, "config", "database-client.ini")}", "shutdown"],
                Path.Combine(root, "database", "bin"),
                TimeSpan.FromSeconds(30),
                progress: null,
                percent: 72);
            await process.WaitForExitAsync().WaitAsync(TimeSpan.FromSeconds(30));
        }
        finally
        {
            if (!process.HasExited)
            {
                process.Kill(entireProcessTree: true);
            }
            process.Dispose();
        }
    }

    private static void WriteDatabaseConfiguration(string root, string password, int databasePort = DatabasePort)
    {
        var database = Path.Combine(root, "database");
        var normalizedDatabase = database.Replace('\\', '/');
        var ini = $"""
            [client]
            host=127.0.0.1
            port={databasePort}
            protocol=tcp
            default-character-set=utf8mb4

            [mysqld]
            basedir={normalizedDatabase}
            datadir={normalizedDatabase}/data
            port={databasePort}
            bind-address=127.0.0.1
            skip-name-resolve
            character-set-server=utf8mb4
            collation-server=utf8mb4_unicode_ci
            max_allowed_packet=256M
            innodb_buffer_pool_size=128M
            innodb_log_file_size=64M
            log_error={normalizedDatabase}/data/mysql_error.log
            pid_file={normalizedDatabase}/data/tableplay-mysql.pid
            """;
        File.WriteAllText(Path.Combine(database, "my.ini"), ini, new UTF8Encoding(false));

        var client = $"""
            [client]
            host=127.0.0.1
            port={databasePort}
            protocol=tcp
            user={DatabaseUser}
            password={password}
            """;
        File.WriteAllText(Path.Combine(root, "config", "database-client.ini"), client, new UTF8Encoding(false));
    }

    private static string WriteDatabaseInitializationScript(string root, string password)
    {
        if (!System.Text.RegularExpressions.Regex.IsMatch(password, "^[A-Za-z0-9]+$"))
            throw new InvalidDataException("The generated private database password contains unsupported characters.");
        var path = Path.Combine(root, "config", "initialize-database.sql");
        var sql = $"""
            CREATE DATABASE IF NOT EXISTS `tableplay` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
            CREATE OR REPLACE USER '{DatabaseUser}'@'127.0.0.1' IDENTIFIED BY '{password}';
            GRANT ALL PRIVILEGES ON `tableplay`.* TO '{DatabaseUser}'@'127.0.0.1';
            GRANT SHUTDOWN ON *.* TO '{DatabaseUser}'@'127.0.0.1';
            FLUSH PRIVILEGES;
            """;
        File.WriteAllText(path, sql, new UTF8Encoding(false));
        return path;
    }

    private static void WriteLaravelEnvironment(
        string root,
        string localIp,
        string databaseUser,
        string databasePassword,
        string appKey,
        string reverbSecret,
        LicenseTrustConfiguration licenseTrust,
        int databasePort = DatabasePort)
    {
        var environment = $"""
            APP_NAME=TablePlay
            APP_ENV=production
            APP_KEY={appKey}
            APP_DEBUG=false
            APP_URL=http://{localIp}:{ServerPort}
            APP_LOCALE=en
            APP_FALLBACK_LOCALE=en
            APP_TIMEZONE=Asia/Kolkata
            LOG_CHANNEL=daily
            LOG_LEVEL=warning
            DB_CONNECTION=mysql
            DB_HOST=127.0.0.1
            DB_PORT={databasePort}
            DB_DATABASE=tableplay
            DB_USERNAME={databaseUser}
            DB_PASSWORD={databasePassword}
            SESSION_DRIVER=database
            SESSION_LIFETIME=525600
            SESSION_ENCRYPT=true
            CACHE_STORE=database
            QUEUE_CONNECTION=database
            BROADCAST_CONNECTION=reverb
            REVERB_APP_ID=tableplay-local
            REVERB_APP_KEY=tableplay-local
            REVERB_APP_SECRET={reverbSecret}
            REVERB_HOST={localIp}
            REVERB_PORT={ReverbPort}
            REVERB_SCHEME=http
            REVERB_SERVER_HOST=0.0.0.0
            REVERB_SERVER_PORT={ReverbPort}
            REVERB_ALLOWED_ORIGIN=*
            VITE_REVERB_APP_KEY=tableplay-local
            VITE_REVERB_HOST={localIp}
            VITE_REVERB_PORT={ReverbPort}
            VITE_REVERB_SCHEME=http
            """;
        File.WriteAllText(Path.Combine(root, "server", ".env"), environment, new UTF8Encoding(false));
        ApplyRestaurantLicenseTrust(root, licenseTrust);

        WritePhpConfiguration(root);

        var installation = new
        {
            ServerPort,
            ReverbPort,
            DatabasePort,
            InstalledAt = DateTimeOffset.Now,
            LocalIp = localIp,
        };
        File.WriteAllText(Path.Combine(root, "config", "installation.json"), JsonSerializer.Serialize(installation, JsonOptions));
    }

    internal static void ApplyRestaurantLicenseTrust(string root, LicenseTrustConfiguration licenseTrust)
    {
        if (!string.Equals(licenseTrust.Algorithm, "Ed25519", StringComparison.Ordinal)
            || !IsValidLicenseKeyId(licenseTrust.KeyId))
            throw new InvalidDataException("The restaurant licensing trust configuration is invalid.");
        var normalizedPublicKey = NormalizeEd25519PublicKey(licenseTrust.PublicKey, "The restaurant Ed25519 public key");
        var publicKey = Convert.FromBase64String(normalizedPublicKey);
        var fingerprint = Convert.ToHexString(SHA256.HashData(publicKey)).ToLowerInvariant();
        if (!fingerprint.Equals(licenseTrust.PublicKeySha256, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("The restaurant Ed25519 public-key fingerprint is invalid.");

        var environmentPath = Path.Combine(root, "server", ".env");
        if (!File.Exists(environmentPath)) throw new InvalidDataException("The TablePlay production environment is missing.");
        var existingTrustedPublicKeys = ReadExistingTrustedLicenseKeys(environmentPath);
        var trustedPublicKeys = new SortedDictionary<string, string>(StringComparer.Ordinal);
        if (licenseTrust.TrustedPublicKeys is null || licenseTrust.TrustedPublicKeys.Count > 64)
            throw new InvalidDataException("The restaurant trusted licensing key map is invalid.");
        foreach (var entry in licenseTrust.TrustedPublicKeys)
        {
            if (!IsValidLicenseKeyId(entry.Key))
                throw new InvalidDataException("The restaurant trusted licensing key map contains an invalid key ID.");
            trustedPublicKeys[entry.Key] = NormalizeEd25519PublicKey(entry.Value, $"The trusted Ed25519 public key '{entry.Key}'");
        }
        if (trustedPublicKeys.TryGetValue(licenseTrust.KeyId, out var bundledCurrent)
            && !string.Equals(bundledCurrent, normalizedPublicKey, StringComparison.Ordinal))
            throw new InvalidDataException("The restaurant current public key conflicts with its trusted key map.");
        if (!trustedPublicKeys.ContainsKey(licenseTrust.KeyId) && trustedPublicKeys.Count >= 64)
            throw new InvalidDataException("The restaurant trusted licensing key map has no room for its current key.");
        trustedPublicKeys[licenseTrust.KeyId] = normalizedPublicKey;
        foreach (var entry in existingTrustedPublicKeys.OrderBy(item => item.Key, StringComparer.Ordinal))
        {
            if (trustedPublicKeys.Count >= 64) break;
            trustedPublicKeys.TryAdd(entry.Key, entry.Value);
        }
        var trustedPublicKeysJson = JsonSerializer.Serialize(trustedPublicKeys);
        var replacements = new Dictionary<string, string>(StringComparer.Ordinal)
        {
            ["TABLEPLAY_MODE"] = "restaurant",
            ["TABLEPLAY_CLOUD_CONSOLE"] = "false",
            ["TABLEPLAY_LICENSE_PUBLIC_KEY"] = normalizedPublicKey,
            ["TABLEPLAY_LICENSE_KEY_ID"] = licenseTrust.KeyId,
            ["TABLEPLAY_TRUSTED_LICENSE_PUBLIC_KEYS"] = "'" + trustedPublicKeysJson + "'",
        };
        var seen = new HashSet<string>(StringComparer.Ordinal);
        var output = new List<string>();
        foreach (var line in File.ReadAllLines(environmentPath))
        {
            var separator = line.IndexOf('=');
            if (separator <= 0)
            {
                output.Add(line);
                continue;
            }
            var key = line[..separator].Trim();
            if (key.Equals("TABLEPLAY_LICENSE_PRIVATE_KEY", StringComparison.Ordinal)) continue;
            if (!replacements.TryGetValue(key, out var value))
            {
                output.Add(line);
                continue;
            }
            if (seen.Add(key)) output.Add(key + "=" + value);
        }
        foreach (var replacement in replacements.Where(item => seen.Add(item.Key)))
            output.Add(replacement.Key + "=" + replacement.Value);
        File.WriteAllLines(environmentPath, output, new UTF8Encoding(false));
    }

    private static SortedDictionary<string, string> ReadExistingTrustedLicenseKeys(string environmentPath)
    {
        var trusted = new SortedDictionary<string, string>(StringComparer.Ordinal);
        string? currentKeyId = null;
        string? currentPublicKey = null;
        foreach (var line in File.ReadAllLines(environmentPath))
        {
            var separator = line.IndexOf('=');
            if (separator <= 0) continue;
            var key = line[..separator].Trim();
            var value = UnquoteEnvironmentValue(line[(separator + 1)..]);
            if (key.Equals("TABLEPLAY_LICENSE_KEY_ID", StringComparison.Ordinal)) currentKeyId = value;
            else if (key.Equals("TABLEPLAY_LICENSE_PUBLIC_KEY", StringComparison.Ordinal)) currentPublicKey = value;
            else if (key.Equals("TABLEPLAY_TRUSTED_LICENSE_PUBLIC_KEYS", StringComparison.Ordinal))
                AddValidTrustedLicenseJson(trusted, value);
        }

        if (IsValidLicenseKeyId(currentKeyId))
        {
            try { trusted[currentKeyId!] = NormalizeEd25519PublicKey(currentPublicKey, "The previous restaurant Ed25519 public key"); }
            catch (InvalidDataException) { }
        }
        return trusted;
    }

    private static void AddValidTrustedLicenseJson(SortedDictionary<string, string> trusted, string rawJson)
    {
        if (rawJson.Length > 32768) return;
        try
        {
            using var document = JsonDocument.Parse(rawJson);
            if (document.RootElement.ValueKind != JsonValueKind.Object) return;
            foreach (var property in document.RootElement.EnumerateObject())
            {
                if (trusted.Count >= 64) break;
                if (!IsValidLicenseKeyId(property.Name) || property.Value.ValueKind != JsonValueKind.String) continue;
                try { trusted[property.Name] = NormalizeEd25519PublicKey(property.Value.GetString(), "A previous trusted Ed25519 public key"); }
                catch (InvalidDataException) { }
            }
        }
        catch (JsonException) { }
    }

    private static string UnquoteEnvironmentValue(string value)
    {
        value = value.Trim();
        if (value.Length >= 2
            && ((value[0] == '\'' && value[^1] == '\'') || (value[0] == '"' && value[^1] == '"')))
            return value[1..^1];
        return value;
    }

    internal static void WritePhpConfiguration(string root)
    {
        var phpIni = Path.Combine(root, "php", "php.ini");
        var extensionDirectory = Path.Combine(root, "php", "ext").Replace('\\', '/');
        var phpConfiguration = $"""
            [PHP]
            extension_dir="{extensionDirectory}"
            date.timezone=Asia/Kolkata
            memory_limit=512M
            upload_max_filesize=256M
            post_max_size=260M
            max_execution_time=600
            variables_order="GPCS"
            display_errors=Off
            log_errors=On
            realpath_cache_size=4096K
            realpath_cache_ttl=600
            zend_extension=php_opcache.dll
            opcache.enable=1
            opcache.enable_cli=1
            opcache.memory_consumption=128
            opcache.interned_strings_buffer=16
            opcache.max_accelerated_files=10000
            opcache.validate_timestamps=0
            opcache.jit=off
            extension=curl
            extension=fileinfo
            extension=mbstring
            extension=openssl
            extension=pdo_mysql
            extension=mysqli
            extension=php_sodium.dll
            extension=zip
            extension=intl
            """;
        File.WriteAllText(phpIni, phpConfiguration, new UTF8Encoding(false));
    }

    internal static async Task ValidatePhpRuntimeAsync(string root, IProgress<InstallProgress>? progress, int percent)
    {
        var php = Path.Combine(root, "php", "php.exe");
        var validation = Path.Combine(root, "config", "validate-php-runtime.php");
        File.WriteAllText(
            validation,
            "<?php $required=['mbstring','openssl','pdo_mysql','mysqli','curl','fileinfo','zip','intl','sodium','Zend OPcache']; "
            + "$missing=array_values(array_filter($required,fn($name)=>!extension_loaded($name))); "
            + "if($missing){fwrite(STDERR,'Missing PHP extensions: '.implode(', ',$missing)); exit(1);} "
            + "$pair=sodium_crypto_sign_keypair(); $private=sodium_crypto_sign_secretkey($pair); $public=sodium_crypto_sign_publickey($pair); "
            + "$message=random_bytes(32); $signature=sodium_crypto_sign_detached($message,$private); "
            + "if(!sodium_crypto_sign_verify_detached($signature,$message,$public)){fwrite(STDERR,'Ed25519 signing self-test failed.'); exit(1);} "
            + "sodium_memzero($private); echo 'PHP runtime and Ed25519 ready: '.PHP_VERSION;",
            new UTF8Encoding(false));
        try
        {
            await RunProcessAsync(php, [validation], Path.Combine(root, "server"), TimeSpan.FromMinutes(1), progress, percent);
        }
        finally
        {
            try { File.Delete(validation); } catch { }
        }
    }

    internal static async Task RunOfflineLifecycleSmokeTestAsync(string payloadRoot, string testRoot)
    {
        if (Directory.Exists(testRoot)) DeleteDirectorySafely(testRoot);
        var snapshot = testRoot + "-snapshot";
        Process? database = null;
        var progress = new Progress<InstallProgress>(_ => { });
        try
        {
            var licenseTrust = ValidatePayload(payloadRoot);
            CopyDirectory(payloadRoot, testRoot);
            Directory.CreateDirectory(Path.Combine(testRoot, "config"));
            Directory.CreateDirectory(Path.Combine(testRoot, "logs"));
            Directory.CreateDirectory(Path.Combine(testRoot, "backups"));
            Directory.CreateDirectory(Path.Combine(testRoot, "server", "storage", "app", "updates"));
            var databasePassword = RandomToken(36);
            var databasePort = FindAvailableLoopbackPort();
            WriteDatabaseConfiguration(testRoot, databasePassword, databasePort);
            await InitializeDatabaseAsync(testRoot, databasePassword, progress, databasePort);
            var databaseInitialization = WriteDatabaseInitializationScript(testRoot, databasePassword);
            try
            {
                database = StartTemporaryDatabase(testRoot, databaseInitialization);
                await WaitForDatabaseAsync(testRoot, database, TimeSpan.FromSeconds(60), databasePort);
            }
            finally
            {
                try { File.Delete(databaseInitialization); } catch { }
            }
            WriteLaravelEnvironment(
                testRoot,
                "127.0.0.1",
                DatabaseUser,
                databasePassword,
                "base64:" + Convert.ToBase64String(RandomNumberGenerator.GetBytes(32)),
                RandomToken(48),
                licenseTrust,
                databasePort);
            AssertRestaurantLicenseEnvironment(testRoot, licenseTrust);
            await ValidatePhpRuntimeAsync(testRoot, progress, 15);
            var php = Path.Combine(testRoot, "php", "php.exe");
            var server = Path.Combine(testRoot, "server");
            await RunProcessAsync(php, ["artisan", "migrate", "--force"], server, TimeSpan.FromMinutes(10), progress, 20);
            await RunProcessAsync(php, ["artisan", "db:seed", "--force"], server, TimeSpan.FromMinutes(10), progress, 25);
            var releases = PrepareBundledReleases(testRoot);
            var bootstrap = WriteBootstrapConfiguration(testRoot, new InstallOptions("Lifecycle Test Restaurant", "test@tableplay.local", "LifecycleTest@123"), releases);
            await RunProcessAsync(php, ["artisan", "tableplay:bootstrap-installation", bootstrap, "--delete-config"], server, TimeSpan.FromMinutes(5), progress, 30);
            EnsurePublicStorageLink(testRoot);
            await RunProcessAsync(php, ["artisan", "optimize"], server, TimeSpan.FromMinutes(5), progress, 35);
            var preservedFile = Path.Combine(server, "storage", "app", "public", "lifecycle-preserved.txt");
            File.WriteAllText(preservedFile, "preserved");
            await StopTemporaryDatabaseAsync(testRoot, database);
            database = null;

            // Simulate a legacy/misconfigured restaurant environment. Upgrade
            // must replace the trust anchor and remove every private-key line.
            File.AppendAllText(
                Path.Combine(testRoot, "server", ".env"),
                $"{Environment.NewLine}TABLEPLAY_LICENSE_PRIVATE_KEY=must-not-survive-upgrade{Environment.NewLine}TABLEPLAY_LICENSE_PUBLIC_KEY=invalid-legacy-key{Environment.NewLine}",
                new UTF8Encoding(false));

            SnapshotReplaceableFiles(testRoot, snapshot);
            ReplaceApplicationFiles(payloadRoot, testRoot, snapshot);
            EnsureLaravelEnvironment(testRoot, licenseTrust);
            ApplyRestaurantLicenseTrust(testRoot, licenseTrust);
            AssertRestaurantLicenseEnvironment(testRoot, licenseTrust);
            WritePhpConfiguration(testRoot);
            await ValidatePhpRuntimeAsync(testRoot, progress, 50);
            database = StartTemporaryDatabase(testRoot);
            await WaitForDatabaseAsync(testRoot, database, TimeSpan.FromSeconds(60), databasePort);
            php = Path.Combine(testRoot, "php", "php.exe");
            server = Path.Combine(testRoot, "server");
            await RunProcessAsync(php, ["artisan", "migrate", "--force"], server, TimeSpan.FromMinutes(10), progress, 60);
            releases = PrepareBundledReleases(testRoot);
            var upgrade = WriteUpgradeConfiguration(testRoot, releases);
            await RunProcessAsync(php, ["artisan", "tableplay:bootstrap-upgrade", upgrade, "--delete-config"], server, TimeSpan.FromMinutes(5), progress, 70);
            EnsurePublicStorageLink(testRoot);
            await RunProcessAsync(php, ["artisan", "optimize"], server, TimeSpan.FromMinutes(5), progress, 80);
            if (!File.Exists(preservedFile) || File.ReadAllText(preservedFile) != "preserved")
                throw new InvalidDataException("The lifecycle upgrade did not preserve restaurant storage.");
            await RunProcessAsync(
                Path.Combine(testRoot, "database", "bin", "mysql.exe"),
                [$"--defaults-extra-file={Path.Combine(testRoot, "config", "database-client.ini")}", "--batch", "--skip-column-names", "--execute=SELECT COUNT(*) FROM tableplay.users;"],
                Path.Combine(testRoot, "database", "bin"),
                TimeSpan.FromMinutes(1), progress, 90);
            await StopTemporaryDatabaseAsync(testRoot, database);
            database = null;
        }
        finally
        {
            if (database is not null)
            {
                try { await StopTemporaryDatabaseAsync(testRoot, database); }
                catch { try { database.Kill(entireProcessTree: true); } catch { } }
            }
            try { DeleteDirectorySafely(testRoot); } catch { }
            try { DeleteDirectorySafely(snapshot); } catch { }
        }
    }

    internal static void AssertRestaurantLicenseEnvironment(string root, LicenseTrustConfiguration licenseTrust)
    {
        var environmentPath = Path.Combine(root, "server", ".env");
        if (!File.Exists(environmentPath)) throw new InvalidDataException("The lifecycle environment file is missing.");
        var values = new Dictionary<string, List<string>>(StringComparer.Ordinal);
        foreach (var line in File.ReadAllLines(environmentPath))
        {
            var separator = line.IndexOf('=');
            if (separator <= 0) continue;
            var key = line[..separator].Trim();
            if (!values.TryGetValue(key, out var entries)) values[key] = entries = [];
            entries.Add(line[(separator + 1)..]);
        }

        if (values.ContainsKey("TABLEPLAY_LICENSE_PRIVATE_KEY"))
            throw new InvalidDataException("A restaurant installation contains a forbidden TablePlay private licence key.");
        AssertSingleEnvironmentValue(values, "TABLEPLAY_MODE", "restaurant");
        AssertSingleEnvironmentValue(values, "TABLEPLAY_CLOUD_CONSOLE", "false");
        AssertSingleEnvironmentValue(values, "TABLEPLAY_LICENSE_PUBLIC_KEY", licenseTrust.PublicKey);
        AssertSingleEnvironmentValue(values, "TABLEPLAY_LICENSE_KEY_ID", licenseTrust.KeyId);
        if (!values.TryGetValue("TABLEPLAY_TRUSTED_LICENSE_PUBLIC_KEYS", out var trustedEntries) || trustedEntries.Count != 1)
            throw new InvalidDataException("The lifecycle environment does not contain exactly one trusted public-key map.");
        var trustedPublicKeys = new SortedDictionary<string, string>(StringComparer.Ordinal);
        AddValidTrustedLicenseJson(trustedPublicKeys, UnquoteEnvironmentValue(trustedEntries[0]));
        foreach (var expected in licenseTrust.TrustedPublicKeys.Append(new KeyValuePair<string, string>(licenseTrust.KeyId, licenseTrust.PublicKey)))
        {
            if (!trustedPublicKeys.TryGetValue(expected.Key, out var actual)
                || !string.Equals(actual, expected.Value, StringComparison.Ordinal))
                throw new InvalidDataException($"The lifecycle environment does not trust the expected Ed25519 key '{expected.Key}'.");
        }
    }

    private static void AssertSingleEnvironmentValue(
        Dictionary<string, List<string>> values,
        string key,
        string expected)
    {
        if (!values.TryGetValue(key, out var entries) || entries.Count != 1 || !string.Equals(entries[0], expected, StringComparison.Ordinal))
            throw new InvalidDataException($"The lifecycle environment does not contain exactly one valid {key} value.");
    }

    private static List<Dictionary<string, object?>> PrepareBundledReleases(string root)
    {
        var manifestPath = Path.Combine(root, "payload-manifest.json");
        using var manifest = JsonDocument.Parse(File.ReadAllText(manifestPath));
        var releases = new List<Dictionary<string, object?>>();
        var updates = Path.Combine(root, "server", "storage", "app", "updates");
        Directory.CreateDirectory(updates);

        foreach (var release in manifest.RootElement.GetProperty("releases").EnumerateArray())
        {
            var sourceName = release.GetProperty("payload_file").GetString()!;
            var destinationName = release.GetProperty("storage_file").GetString()!;
            var source = Path.Combine(root, "packages", sourceName);
            var destination = Path.Combine(updates, destinationName);
            File.Copy(source, destination, overwrite: true);
            var info = new FileInfo(destination);
            var sha = Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(destination))).ToLowerInvariant();

            releases.Add(new Dictionary<string, object?>
            {
                ["app"] = release.GetProperty("app").GetString(),
                ["platform"] = release.GetProperty("platform").GetString(),
                ["version"] = release.GetProperty("version").GetString(),
                ["build_number"] = release.TryGetProperty("build_number", out var build) ? build.GetInt32() : null,
                ["file_path"] = destinationName,
                ["original_filename"] = release.GetProperty("original_filename").GetString(),
                ["mime_type"] = release.GetProperty("mime_type").GetString(),
                ["file_size"] = info.Length,
                ["sha256"] = sha,
                ["package_identifier"] = release.TryGetProperty("package_identifier", out var package) ? package.GetString() : null,
                ["signing_certificate_sha256"] = release.TryGetProperty("signing_certificate_sha256", out var certificate) ? certificate.GetString() : null,
            });
        }

        return releases;
    }

    private static string WriteBootstrapConfiguration(string root, InstallOptions options, List<Dictionary<string, object?>> releases)
    {
        var path = Path.Combine(root, "config", "installer-bootstrap.json");
        var data = new Dictionary<string, object?>
        {
            ["restaurant_name"] = options.RestaurantName,
            ["admin_email"] = options.AdminEmail,
            ["admin_password"] = options.AdminPassword,
            ["releases"] = releases,
        };
        File.WriteAllText(path, JsonSerializer.Serialize(data, JsonOptions));
        return path;
    }

    private static void InstallWindowsService(string root)
    {
        RunSystemCommand("sc.exe", ["stop", ServiceName], TimeSpan.FromSeconds(15), allowedExitCodes: [0, 1060, 1062]);
        var serviceExecutable = Path.Combine(root, "services", "TablePlay.ServiceHost.exe");
        // SCM stores binPath as a command line. Explicit quotes are required
        // for installations below C:\Program Files.
        var quotedServiceExecutable = $"\"{serviceExecutable}\"";
        var existingService = RunSystemCommand("sc.exe", ["query", ServiceName], TimeSpan.FromSeconds(15), allowedExitCodes: [0, 1060], captureOutput: true);
        if (existingService.Contains("SERVICE_NAME", StringComparison.OrdinalIgnoreCase))
            RunSystemCommand("sc.exe", ["config", ServiceName, "binPath=", quotedServiceExecutable, "start=", "auto", "DisplayName=", "TablePlay Server"], TimeSpan.FromSeconds(30));
        else
            RunSystemCommand("sc.exe", ["create", ServiceName, "binPath=", quotedServiceExecutable, "start=", "auto", "DisplayName=", "TablePlay Server"], TimeSpan.FromSeconds(30));
        RunSystemCommand("sc.exe", ["description", ServiceName, "Runs TablePlay database, Laravel, Reverb and queue services."], TimeSpan.FromSeconds(15));
        RunSystemCommand("sc.exe", ["failure", ServiceName, "reset=", "86400", "actions=", "restart/5000/restart/15000/restart/30000"], TimeSpan.FromSeconds(15));
    }

    private static void AddPrivateFirewallRules()
    {
        RunSystemCommand("netsh.exe", ["advfirewall", "firewall", "delete", "rule", "name=TablePlay Web"], TimeSpan.FromSeconds(15), allowedExitCodes: [0, 1]);
        RunSystemCommand("netsh.exe", ["advfirewall", "firewall", "delete", "rule", "name=TablePlay Realtime"], TimeSpan.FromSeconds(15), allowedExitCodes: [0, 1]);
        RunSystemCommand("netsh.exe", ["advfirewall", "firewall", "add", "rule", "name=TablePlay Web", "dir=in", "action=allow", "protocol=TCP", $"localport={ServerPort}", "profile=private"], TimeSpan.FromSeconds(15));
        RunSystemCommand("netsh.exe", ["advfirewall", "firewall", "add", "rule", "name=TablePlay Realtime", "dir=in", "action=allow", "protocol=TCP", $"localport={ReverbPort}", "profile=private"], TimeSpan.FromSeconds(15));
    }

    private static void CreateShortcuts(string root, string localIp)
    {
        var startMenu = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonPrograms), "TablePlay");
        Directory.CreateDirectory(startMenu);
        CreateInternetShortcut(Path.Combine(startMenu, "TablePlay Admin.url"), $"http://{localIp}:{ServerPort}/login", Path.Combine(root, "server", "public", "favicon.ico"));
        CreateShellShortcut(Path.Combine(startMenu, "TablePlay Server Manager.lnk"), Path.Combine(root, "server-manager", "TablePlay.ServerManager.exe"), root, Path.Combine(root, "server", "public", "favicon.ico"));

        var desktop = Environment.GetFolderPath(Environment.SpecialFolder.CommonDesktopDirectory);
        CreateInternetShortcut(Path.Combine(desktop, "TablePlay Admin.url"), $"http://{localIp}:{ServerPort}/login", Path.Combine(root, "server", "public", "favicon.ico"));
        CreateShellShortcut(Path.Combine(desktop, "TablePlay Server Manager.lnk"), Path.Combine(root, "server-manager", "TablePlay.ServerManager.exe"), root, Path.Combine(root, "server", "public", "favicon.ico"));
    }

    private static void CreateInternetShortcut(string path, string url, string icon)
    {
        File.WriteAllText(path, $"[InternetShortcut]{Environment.NewLine}URL={url}{Environment.NewLine}IconFile={icon}{Environment.NewLine}IconIndex=0{Environment.NewLine}");
    }

    private static void CreateShellShortcut(string path, string target, string workingDirectory, string icon)
    {
        var shellType = Type.GetTypeFromProgID("WScript.Shell") ?? throw new InvalidOperationException("Windows Script Host is unavailable.");
        dynamic shell = Activator.CreateInstance(shellType)!;
        dynamic shortcut = shell.CreateShortcut(path);
        shortcut.TargetPath = target;
        shortcut.WorkingDirectory = workingDirectory;
        shortcut.IconLocation = icon + ",0";
        shortcut.Description = "Manage the local TablePlay server";
        shortcut.Save();
    }

    private static void RegisterUninstaller(string root)
    {
        using var key = Registry.LocalMachine.CreateSubKey(@"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\TablePlay", writable: true);
        key.SetValue("DisplayName", "TablePlay Restaurant System");
        key.SetValue("DisplayVersion", "1.0.0");
        key.SetValue("Publisher", "TablePlay");
        key.SetValue("InstallLocation", root);
        key.SetValue("DisplayIcon", Path.Combine(root, "server", "public", "favicon.ico"));
        key.SetValue("UninstallString", $"\"{Path.Combine(root, "server-manager", "TablePlay.ServerManager.exe")}\" --uninstall");
        key.SetValue("NoModify", 1, RegistryValueKind.DWord);
    }

    private static async Task RunProcessAsync(string executable, IReadOnlyList<string> arguments, string workingDirectory, TimeSpan timeout, IProgress<InstallProgress>? progress, int percent, bool includeOutput = true)
    {
        var processLabel = DescribeProcess(executable, arguments);
        AppendInstallerTranscript(workingDirectory, $"START {processLabel}");
        var startInfo = new ProcessStartInfo
        {
            FileName = executable,
            WorkingDirectory = workingDirectory,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
        };
        foreach (var argument in arguments) startInfo.ArgumentList.Add(argument);

        using var process = Process.Start(startInfo) ?? throw new InvalidOperationException("Windows could not start " + Path.GetFileName(executable) + ".");
        var outputTask = process.StandardOutput.ReadToEndAsync();
        var errorTask = process.StandardError.ReadToEndAsync();
        try
        {
            await process.WaitForExitAsync().WaitAsync(timeout);
        }
        catch (TimeoutException)
        {
            process.Kill(entireProcessTree: true);
            throw new TimeoutException(Path.GetFileName(executable) + " did not finish within " + timeout.TotalMinutes.ToString("0.0") + " minutes.");
        }

        var output = await outputTask;
        var error = await errorTask;
        AppendInstallerTranscript(workingDirectory, $"END {processLabel} exit={process.ExitCode}{Environment.NewLine}{output}{Environment.NewLine}{error}");
        if (includeOutput && progress is not null)
        {
            var detail = string.Join(Environment.NewLine, new[] { output, error }.Where(value => !string.IsNullOrWhiteSpace(value)));
            if (!string.IsNullOrWhiteSpace(detail)) progress.Report(new InstallProgress(percent, "Configuring TablePlay…", detail));
        }
        if (process.ExitCode != 0)
        {
            var diagnostic = string.Join(
                Environment.NewLine,
                new[] { output, error }.Where(value => !string.IsNullOrWhiteSpace(value))).Trim();
            if (string.IsNullOrWhiteSpace(diagnostic)) diagnostic = "The process returned no diagnostic output.";
            throw new InvalidOperationException(
                $"{processLabel} failed with exit code {process.ExitCode}:{Environment.NewLine}{diagnostic}");
        }
    }

    private static string DescribeProcess(string executable, IReadOnlyList<string> arguments)
    {
        var artisanIndex = arguments.ToList().FindIndex(argument => argument.Equals("artisan", StringComparison.OrdinalIgnoreCase));
        if (artisanIndex >= 0 && artisanIndex + 1 < arguments.Count)
            return "Laravel " + arguments[artisanIndex + 1];
        return Path.GetFileName(executable);
    }

    private static void AppendInstallerTranscript(string workingDirectory, string message)
    {
        try
        {
            var current = new DirectoryInfo(workingDirectory);
            while (current is not null && !Directory.Exists(Path.Combine(current.FullName, "config"))) current = current.Parent;
            if (current is null) return;
            var logs = Path.Combine(current.FullName, "logs");
            Directory.CreateDirectory(logs);
            var bounded = message.Length > 12000 ? message[^12000..] : message;
            AppendSetupLog(Path.Combine(logs, "setup-commands.log"), bounded);
        }
        catch { }
    }

    private static string RunSystemCommand(string executable, IReadOnlyList<string> arguments, TimeSpan timeout, IReadOnlyCollection<int>? allowedExitCodes = null, bool captureOutput = false)
    {
        allowedExitCodes ??= [0];
        var startInfo = new ProcessStartInfo
        {
            FileName = executable,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
        };
        foreach (var argument in arguments) startInfo.ArgumentList.Add(argument);
        using var process = Process.Start(startInfo) ?? throw new InvalidOperationException("Windows could not start " + executable + ".");
        var outputTask = process.StandardOutput.ReadToEndAsync();
        var errorTask = process.StandardError.ReadToEndAsync();
        if (!process.WaitForExit((int)timeout.TotalMilliseconds))
        {
            process.Kill(entireProcessTree: true);
            process.WaitForExit();
            throw new TimeoutException(executable + " timed out.");
        }
        var output = outputTask.GetAwaiter().GetResult();
        var error = errorTask.GetAwaiter().GetResult();
        if (!allowedExitCodes.Contains(process.ExitCode))
        {
            var diagnostic = string.Join(Environment.NewLine, new[] { output, error }.Where(value => !string.IsNullOrWhiteSpace(value))).Trim();
            throw new InvalidOperationException($"{executable} failed with exit code {process.ExitCode}: {diagnostic}");
        }
        return captureOutput ? output : string.Empty;
    }

    private static async Task WaitForPortAsync(string host, int port, TimeSpan timeout)
    {
        var deadline = DateTimeOffset.UtcNow + timeout;
        while (DateTimeOffset.UtcNow < deadline)
        {
            try
            {
                using var client = new TcpClient();
                await client.ConnectAsync(host, port).WaitAsync(TimeSpan.FromSeconds(2));
                return;
            }
            catch
            {
                await Task.Delay(500);
            }
        }
        throw new TimeoutException($"Port {port} did not become ready.");
    }

    private static async Task WaitForHttpAsync(string url, TimeSpan timeout, string? installRoot = null)
    {
        using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(3) };
        var deadline = DateTimeOffset.UtcNow + timeout;
        while (DateTimeOffset.UtcNow < deadline)
        {
            try
            {
                using var response = await client.GetAsync(url);
                if (response.IsSuccessStatusCode) return;
            }
            catch
            {
                // The service is still starting.
            }
            await Task.Delay(1000);
        }
        var diagnostics = installRoot is null ? string.Empty : ReadStartupDiagnostics(installRoot);
        throw new TimeoutException(
            "TablePlay did not pass its HTTP health check at " + url + "."
            + (string.IsNullOrWhiteSpace(diagnostics) ? string.Empty : Environment.NewLine + diagnostics));
    }

    private static async Task ValidateInstalledRuntimeAsync(string root, string localIp)
    {
        RunSystemCommand(
            Path.Combine(root, "database", "bin", "mysql.exe"),
            [$"--defaults-extra-file={Path.Combine(root, "config", "database-client.ini")}", "--batch", "--skip-column-names", "--execute=SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='tableplay';"],
            TimeSpan.FromSeconds(20));

        await WaitForPortAsync("127.0.0.1", ReverbPort, RuntimeValidationTimeout);

        var statusPath = Path.Combine(root, "logs", "runtime-status.json");
        var requiredProcesses = new[] { "database", "laravel", "reverb", "queue", "scheduler" };
        var deadline = DateTimeOffset.UtcNow + RuntimeValidationTimeout;
        string[] missing = requiredProcesses;
        while (DateTimeOffset.UtcNow < deadline)
        {
            try
            {
                using var status = JsonDocument.Parse(File.ReadAllText(statusPath));
                var processes = status.RootElement.GetProperty("processes");
                missing = requiredProcesses.Where(name =>
                    !processes.TryGetProperty(name, out var process)
                    || !process.TryGetProperty("running", out var running)
                    || !running.GetBoolean()).ToArray();
                if (missing.Length == 0) break;
            }
            catch (Exception exception) when (exception is IOException or JsonException or KeyNotFoundException) { }
            await Task.Delay(500);
        }
        if (missing.Length > 0) throw new InvalidOperationException("Required TablePlay runtime processes are not healthy: " + string.Join(", ", missing) + ".");

        using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(30) };
        using var response = await client.GetAsync($"http://{localIp}:{ServerPort}/api/v1/branding");
        response.EnsureSuccessStatusCode();
        using var branding = JsonDocument.Parse(await response.Content.ReadAsStringAsync());
        if (branding.RootElement.ValueKind != JsonValueKind.Object
            || !branding.RootElement.TryGetProperty("restaurant_name", out var name)
            || string.IsNullOrWhiteSpace(name.GetString()))
            throw new InvalidDataException("Port 8000 responded, but it was not a valid TablePlay restaurant API.");

        var publicStorage = Path.Combine(root, "server", "public", "storage");
        if ((File.GetAttributes(publicStorage) & FileAttributes.ReparsePoint) == 0)
            throw new IOException("The TablePlay public storage link is not healthy.");
    }

    private static void ValidateRollbackRuntime(string root)
    {
        if (!IsServiceRunning()) throw new InvalidOperationException("The restored TablePlay Windows service is not running.");
        RunSystemCommand(
            Path.Combine(root, "database", "bin", "mysql.exe"),
            [$"--defaults-extra-file={Path.Combine(root, "config", "database-client.ini")}", "--batch", "--skip-column-names", "--execute=SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='tableplay';"],
            TimeSpan.FromSeconds(30));
    }

    private static string ReadStartupDiagnostics(string installRoot)
    {
        var paths = new[]
        {
            Path.Combine(installRoot, "logs", "service-errors.log"),
            Path.Combine(installRoot, "logs", "runtime.log"),
            Path.Combine(installRoot, "logs", "laravel.log"),
            Path.Combine(installRoot, "server", "storage", "logs", "laravel.log"),
            Path.Combine(installRoot, "database", "data", "mysql_error.log"),
        };

        var sections = new List<string>();
        foreach (var path in paths.Where(File.Exists))
        {
            try
            {
                sections.Add(
                    $"--- {Path.GetFileName(path)} ---{Environment.NewLine}"
                    + string.Join(Environment.NewLine, File.ReadLines(path).TakeLast(25)));
            }
            catch
            {
                // Diagnostics must never hide the original health-check error.
            }
        }

        return string.Join(Environment.NewLine, sections);
    }

    private static string FindLocalIPv4Address()
    {
        var candidates = NetworkInterface.GetAllNetworkInterfaces()
            .Where(adapter => adapter.OperationalStatus == OperationalStatus.Up && adapter.NetworkInterfaceType != NetworkInterfaceType.Loopback)
            .SelectMany(adapter => adapter.GetIPProperties().UnicastAddresses
                .Where(address => address.Address.AddressFamily == AddressFamily.InterNetwork && !IPAddress.IsLoopback(address.Address))
                .Select(address => new { address.Address, HasGateway = adapter.GetIPProperties().GatewayAddresses.Any(gateway => gateway.Address.AddressFamily == AddressFamily.InterNetwork) }))
            .OrderByDescending(candidate => candidate.HasGateway)
            .ToList();
        return candidates.FirstOrDefault()?.Address.ToString() ?? "127.0.0.1";
    }

    private static int FindAvailableLoopbackPort()
    {
        var listener = new TcpListener(IPAddress.Loopback, 0);
        listener.Start();
        try { return ((IPEndPoint)listener.LocalEndpoint).Port; }
        finally { listener.Stop(); }
    }

    private static void EnsureRequiredPortsAreFree()
    {
        var required = new Dictionary<int, string>
        {
            [ServerPort] = "Laravel web server",
            [ReverbPort] = "Reverb real-time server",
            [DatabasePort] = "private database",
        };
        var occupied = IPGlobalProperties.GetIPGlobalProperties()
            .GetActiveTcpListeners()
            .Select(endpoint => endpoint.Port)
            .ToHashSet();
        var conflicts = required
            .Where(item => occupied.Contains(item.Key))
            .Select(item => $"{item.Key} ({item.Value})")
            .ToArray();

        if (conflicts.Length > 0)
        {
            throw new InvalidOperationException(
                "Required local ports are already in use: " + string.Join(", ", conflicts)
                + ". Stop the existing XAMPP/TablePlay development servers, then run setup again.");
        }
    }

    private static string RandomToken(int length)
    {
        const string alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789";
        var bytes = RandomNumberGenerator.GetBytes(length);
        return new string(bytes.Select(value => alphabet[value % alphabet.Length]).ToArray());
    }

    internal static void CopyDirectory(string source, string destination)
    {
        Directory.CreateDirectory(destination);
        foreach (var file in Directory.EnumerateFiles(source))
        {
            if ((File.GetAttributes(file) & FileAttributes.ReparsePoint) != 0) continue;
            var target = Path.Combine(destination, Path.GetFileName(file));
            File.Copy(file, target, overwrite: true);
        }
        foreach (var directory in Directory.EnumerateDirectories(source))
        {
            var info = new DirectoryInfo(directory);
            // Laravel public/storage is a junction back into private storage.
            // Never follow or copy reparse points during install, backup, or rollback.
            if ((info.Attributes & FileAttributes.ReparsePoint) != 0) continue;
            CopyDirectory(directory, Path.Combine(destination, info.Name));
        }
    }

    internal static void DeleteDirectorySafely(string path)
    {
        FileAttributes attributes;
        try { attributes = File.GetAttributes(path); }
        catch (FileNotFoundException) { return; }
        catch (DirectoryNotFoundException) { return; }
        var directory = new DirectoryInfo(path);
        if ((attributes & FileAttributes.ReparsePoint) != 0)
        {
            if ((attributes & FileAttributes.Directory) != 0) directory.Delete(recursive: false);
            else File.Delete(path);
            return;
        }

        foreach (var child in directory.EnumerateDirectories()) DeleteDirectorySafely(child.FullName);
        foreach (var file in directory.EnumerateFiles())
        {
            file.Attributes = FileAttributes.Normal;
            file.Delete();
        }
        directory.Delete(recursive: false);
    }

    internal static void EnsurePublicStorageLink(string root)
    {
        var storageTarget = Path.Combine(root, "server", "storage", "app", "public");
        var publicDirectory = Path.Combine(root, "server", "public");
        var publicStorage = Path.Combine(publicDirectory, "storage");
        Directory.CreateDirectory(storageTarget);
        Directory.CreateDirectory(publicDirectory);

        try
        {
            var attributes = File.GetAttributes(publicStorage);
            if ((attributes & FileAttributes.ReparsePoint) != 0)
            {
                DeleteDirectorySafely(publicStorage);
            }
            else if ((attributes & FileAttributes.Directory) != 0)
            {
                MergeDirectoryPreservingConflicts(publicStorage, storageTarget);
                DeleteDirectorySafely(publicStorage);
            }
            else
            {
                throw new IOException("public/storage is a file and cannot be converted into the required storage link.");
            }
        }
        catch (FileNotFoundException) { }
        catch (DirectoryNotFoundException) { }

        Directory.CreateSymbolicLink(publicStorage, storageTarget);
        var createdAttributes = File.GetAttributes(publicStorage);
        if ((createdAttributes & FileAttributes.ReparsePoint) == 0 || !Directory.Exists(publicStorage))
            throw new IOException("TablePlay could not verify the public storage link after creating it.");
    }

    private static void MergeDirectoryPreservingConflicts(string source, string destination)
    {
        Directory.CreateDirectory(destination);
        foreach (var file in Directory.EnumerateFiles(source))
        {
            if ((File.GetAttributes(file) & FileAttributes.ReparsePoint) != 0) continue;
            var target = Path.Combine(destination, Path.GetFileName(file));
            if (!File.Exists(target))
            {
                File.Copy(file, target);
                continue;
            }
            if (SHA256.HashData(File.ReadAllBytes(file)).SequenceEqual(SHA256.HashData(File.ReadAllBytes(target)))) continue;
            var recovered = Path.Combine(
                destination,
                Path.GetFileNameWithoutExtension(file) + ".recovered-" + DateTime.Now.ToString("yyyyMMddHHmmssfff") + Path.GetExtension(file));
            File.Copy(file, recovered);
        }
        foreach (var directory in Directory.EnumerateDirectories(source))
        {
            var info = new DirectoryInfo(directory);
            if ((info.Attributes & FileAttributes.ReparsePoint) != 0) continue;
            MergeDirectoryPreservingConflicts(directory, Path.Combine(destination, info.Name));
        }
    }

    private static readonly JsonSerializerOptions JsonOptions = new() { WriteIndented = true };

    private sealed class BoundedStream(Stream inner, long length) : Stream
    {
        private readonly long _start = inner.Position;
        private long _position;
        public override bool CanRead => true;
        public override bool CanSeek => true;
        public override bool CanWrite => false;
        public override long Length => length;
        public override long Position { get => _position; set => Seek(value, SeekOrigin.Begin); }
        public override int Read(byte[] buffer, int offset, int count)
        {
            if (_position >= length) return 0;
            var read = inner.Read(buffer, offset, (int)Math.Min(count, length - _position));
            _position += read;
            return read;
        }
        public override int Read(Span<byte> buffer)
        {
            if (_position >= length) return 0;
            var read = inner.Read(buffer[..(int)Math.Min(buffer.Length, length - _position)]);
            _position += read;
            return read;
        }
        public override void Flush() { }
        public override long Seek(long offset, SeekOrigin origin)
        {
            var target = origin switch
            {
                SeekOrigin.Begin => offset,
                SeekOrigin.Current => _position + offset,
                SeekOrigin.End => length + offset,
                _ => throw new ArgumentOutOfRangeException(nameof(origin)),
            };
            if (target < 0 || target > length) throw new IOException("Attempted to seek outside the installer payload.");
            inner.Seek(_start + target, SeekOrigin.Begin);
            _position = target;
            return _position;
        }
        public override void SetLength(long value) => throw new NotSupportedException();
        public override void Write(byte[] buffer, int offset, int count) => throw new NotSupportedException();
    }
}
