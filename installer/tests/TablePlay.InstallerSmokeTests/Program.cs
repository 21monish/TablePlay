using TablePlay.Setup;
using System.Security.Cryptography;
using System.Text.Json;

var root = Path.Combine(Path.GetTempPath(), "TablePlayJunctionTest_" + Guid.NewGuid().ToString("N"));
var server = Path.Combine(root, "server");
var publicDirectory = Path.Combine(server, "public");
var privateStorage = Path.Combine(root, "restaurant-data");
var storageLink = Path.Combine(publicDirectory, "storage");
var copy = Path.Combine(root, "copy");

try
{
    var emptyState = Path.Combine(root, "state-empty");
    Directory.CreateDirectory(emptyState);
    if (InstallerEngine.GetInstallationState(emptyState) != InstallationState.Fresh)
        throw new Exception("An empty installation directory was not classified as fresh.");
    var interruptedState = Path.Combine(root, "state-interrupted");
    Directory.CreateDirectory(interruptedState);
    File.WriteAllText(Path.Combine(interruptedState, ".installation-in-progress"), DateTimeOffset.Now.ToString("O"));
    if (InstallerEngine.GetInstallationState(interruptedState) != InstallationState.IncompleteFresh)
        throw new Exception("A marker-only interrupted installation was not classified as restartable.");
    Directory.CreateDirectory(Path.Combine(interruptedState, "config"));
    File.WriteAllText(Path.Combine(interruptedState, "config", "database-client.ini"), "preserve");
    if (InstallerEngine.GetInstallationState(interruptedState) != InstallationState.IncompleteFresh)
        throw new Exception("Installer-generated configuration made an interrupted fresh setup enter an unrecoverable repair loop.");
    File.WriteAllText(Path.Combine(interruptedState, "config", "installation.json"), "{\"ProductVersion\":\"2.4.0\"}");
    if (InstallerEngine.GetInstallationState(interruptedState) != InstallationState.Repair)
        throw new Exception("A health-verified installation version was classified as disposable.");
    var unknownExistingState = Path.Combine(root, "state-unknown-existing");
    Directory.CreateDirectory(unknownExistingState);
    File.WriteAllText(Path.Combine(unknownExistingState, "unknown.dat"), "preserve");
    if (InstallerEngine.GetInstallationState(unknownExistingState) != InstallationState.Repair)
        throw new Exception("An unmarked existing installation was classified as disposable.");
    Console.WriteLine("PASS: installation-state classification never discards recoverable restaurant data.");

    var interruptedFiles = Path.Combine(root, "interrupted-files");
    var recoveryRoot = Path.Combine(root, "recovery");
    Directory.CreateDirectory(Path.Combine(interruptedFiles, "database", "data", "tableplay"));
    var databaseSentinel = Path.Combine(interruptedFiles, "database", "data", "tableplay", "orders.ibd");
    File.WriteAllText(databaseSentinel, "must be preserved");
    var recoveredPath = InstallerEngine.MoveDirectoryToRecovery(interruptedFiles, recoveryRoot);
    if (Directory.Exists(interruptedFiles)) throw new Exception("The interrupted installation remained at the active installation path.");
    if (File.ReadAllText(Path.Combine(recoveredPath, "database", "data", "tableplay", "orders.ibd")) != "must be preserved")
        throw new Exception("Interrupted database files were not preserved in recovery.");
    if (!File.Exists(Path.Combine(recoveredPath, "RECOVERY.txt"))) throw new Exception("The recovery archive is missing its explanation file.");
    Console.WriteLine("PASS: interrupted installation recovery preserves database files without deletion.");

    Directory.CreateDirectory(publicDirectory);
    Directory.CreateDirectory(privateStorage);
    File.WriteAllText(Path.Combine(server, "app.txt"), "application");
    var protectedData = Path.Combine(privateStorage, "customer-upload.txt");
    File.WriteAllText(protectedData, "must survive");
    Directory.CreateSymbolicLink(storageLink, privateStorage);

    InstallerEngine.CopyDirectory(server, copy);
    if (!File.Exists(Path.Combine(copy, "app.txt"))) throw new Exception("Normal application files were not copied.");
    if (Directory.Exists(Path.Combine(copy, "public", "storage"))) throw new Exception("The storage reparse point was followed during copying.");

    InstallerEngine.DeleteDirectorySafely(server);
    if (Directory.Exists(server)) throw new Exception("The replaceable server directory was not deleted.");
    if (!File.Exists(protectedData) || File.ReadAllText(protectedData) != "must survive")
        throw new Exception("Linked restaurant data was modified during safe deletion.");

    Console.WriteLine("PASS: junction-safe copy/delete preserved linked restaurant data.");

    var storageRoot = Path.Combine(root, "storage-repair");
    var legacyPublic = Path.Combine(storageRoot, "server", "public", "storage");
    var privatePublic = Path.Combine(storageRoot, "server", "storage", "app", "public");
    Directory.CreateDirectory(legacyPublic);
    Directory.CreateDirectory(privatePublic);
    File.WriteAllText(Path.Combine(legacyPublic, "legacy.jpg"), "legacy");
    File.WriteAllText(Path.Combine(legacyPublic, "conflict.jpg"), "old");
    File.WriteAllText(Path.Combine(privatePublic, "conflict.jpg"), "new");
    InstallerEngine.EnsurePublicStorageLink(storageRoot);
    if ((File.GetAttributes(legacyPublic) & FileAttributes.ReparsePoint) == 0)
        throw new Exception("Legacy public storage was not converted to a link.");
    if (File.ReadAllText(Path.Combine(privatePublic, "legacy.jpg")) != "legacy")
        throw new Exception("Legacy public storage data was not recovered.");
    if (!Directory.EnumerateFiles(privatePublic, "conflict.recovered-*.jpg").Any())
        throw new Exception("Conflicting legacy storage data was not preserved with a recovery name.");
    Console.WriteLine("PASS: legacy storage migration preserved uploads and verified the link.");

    var licenseEnvironmentRoot = Path.Combine(root, "license-environment");
    Directory.CreateDirectory(Path.Combine(licenseEnvironmentRoot, "server"));
    var licenseEnvironment = Path.Combine(licenseEnvironmentRoot, "server", ".env");
    var legacyPublicBytes = Enumerable.Range(33, 32).Select(value => (byte)value).ToArray();
    var mappedHistoricalBytes = Enumerable.Range(65, 32).Select(value => (byte)value).ToArray();
    var bundledHistoricalBytes = Enumerable.Range(97, 32).Select(value => (byte)value).ToArray();
    var legacyPublicKey = Convert.ToBase64String(legacyPublicBytes);
    var mappedHistoricalKey = Convert.ToBase64String(mappedHistoricalBytes);
    var bundledHistoricalKey = Convert.ToBase64String(bundledHistoricalBytes);
    File.WriteAllText(
        licenseEnvironment,
        "APP_NAME=TablePlay\nTABLEPLAY_MODE=cloud\nTABLEPLAY_CLOUD_CONSOLE=true\n"
        + $"TABLEPLAY_LICENSE_PUBLIC_KEY={legacyPublicKey}\nTABLEPLAY_LICENSE_KEY_ID=tableplay-market-legacy-v0\n"
        + $"TABLEPLAY_TRUSTED_LICENSE_PUBLIC_KEYS='{{\"tableplay-market-mapped-v0\":\"{mappedHistoricalKey}\",\"invalid-old-key\":\"not-base64\"}}'\n"
        + "TABLEPLAY_TRUSTED_LICENSE_PUBLIC_KEYS=broken-json\nTABLEPLAY_LICENSE_PRIVATE_KEY=forbidden\n");
    var testPublicBytes = Enumerable.Range(1, 32).Select(value => (byte)value).ToArray();
    var testPublicKey = Convert.ToBase64String(testPublicBytes);
    var trust = new LicenseTrustConfiguration(
        "Ed25519",
        "tableplay-market-test-v1",
        testPublicKey,
        Convert.ToHexString(SHA256.HashData(testPublicBytes)).ToLowerInvariant(),
        new SortedDictionary<string, string>(StringComparer.Ordinal)
        {
            ["tableplay-market-bundled-v0"] = bundledHistoricalKey,
            ["tableplay-market-test-v1"] = testPublicKey,
        });
    InstallerEngine.ApplyRestaurantLicenseTrust(licenseEnvironmentRoot, trust);
    InstallerEngine.AssertRestaurantLicenseEnvironment(licenseEnvironmentRoot, trust);
    if (File.ReadAllText(licenseEnvironment).Contains("TABLEPLAY_LICENSE_PRIVATE_KEY", StringComparison.Ordinal))
        throw new Exception("Restaurant environment cleanup retained a private licensing key name.");
    var trustedLine = File.ReadAllLines(licenseEnvironment).Single(line => line.StartsWith("TABLEPLAY_TRUSTED_LICENSE_PUBLIC_KEYS=", StringComparison.Ordinal));
    var trustedJson = trustedLine[(trustedLine.IndexOf('=') + 1)..].Trim();
    if (trustedJson.Length >= 2 && trustedJson[0] == '\'' && trustedJson[^1] == '\'') trustedJson = trustedJson[1..^1];
    var installedTrustedKeys = JsonSerializer.Deserialize<Dictionary<string, string>>(trustedJson)
        ?? throw new Exception("Restaurant environment trusted-key map is not valid JSON.");
    var expectedTrustedKeys = new Dictionary<string, string>(StringComparer.Ordinal)
    {
        ["tableplay-market-test-v1"] = testPublicKey,
        ["tableplay-market-bundled-v0"] = bundledHistoricalKey,
        ["tableplay-market-legacy-v0"] = legacyPublicKey,
        ["tableplay-market-mapped-v0"] = mappedHistoricalKey,
    };
    foreach (var expected in expectedTrustedKeys)
        if (!installedTrustedKeys.TryGetValue(expected.Key, out var actual) || actual != expected.Value)
            throw new Exception("Restaurant upgrade failed to preserve trusted Ed25519 key " + expected.Key + ".");
    if (installedTrustedKeys.ContainsKey("invalid-old-key"))
        throw new Exception("Restaurant upgrade retained an invalid historical public key.");
    var conflictingTrust = trust with
    {
        TrustedPublicKeys = new Dictionary<string, string>(StringComparer.Ordinal)
        {
            [trust.KeyId] = legacyPublicKey,
        },
    };
    try
    {
        InstallerEngine.ApplyRestaurantLicenseTrust(licenseEnvironmentRoot, conflictingTrust);
        throw new Exception("Restaurant trust provisioning accepted a conflicting current key ID.");
    }
    catch (InvalidDataException) { }
    Console.WriteLine("PASS: restaurant environment provisions current trust and retains only valid historical Ed25519 public keys.");

    if (args.Length > 0)
    {
        var runtimeRoot = Path.Combine(root, "runtime");
        Directory.CreateDirectory(Path.Combine(runtimeRoot, "server"));
        Directory.CreateDirectory(Path.Combine(runtimeRoot, "config"));
        InstallerEngine.CopyDirectory(args[0], Path.Combine(runtimeRoot, "php"));
        var phpIni = Path.Combine(runtimeRoot, "php", "php.ini");
        if (File.Exists(phpIni)) File.Delete(phpIni);
        InstallerEngine.WritePhpConfiguration(runtimeRoot);
        await InstallerEngine.ValidatePhpRuntimeAsync(runtimeRoot, progress: null, percent: 0);
        Console.WriteLine("PASS: regenerated php.ini loads every required production extension.");
    }
    if (args.Length > 1)
    {
        await InstallerEngine.RunOfflineLifecycleSmokeTestAsync(
            args[1],
            Path.Combine(Path.GetTempPath(), "TablePlayLifecycleTest_" + Guid.NewGuid().ToString("N")));
        Console.WriteLine("PASS: complete fresh-install and in-place-upgrade lifecycle succeeded.");
    }
    return 0;
}
finally
{
    if (Directory.Exists(root)) Directory.Delete(root, recursive: true);
}
