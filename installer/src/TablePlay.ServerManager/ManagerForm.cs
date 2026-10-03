using Microsoft.Win32;
using QRCoder;
using System.Diagnostics;
using System.Drawing;
using System.Net;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Text;
using System.Text.Json;

namespace TablePlay.ServerManager;

internal sealed class ManagerForm : Form
{
    private const string ServiceName = "TablePlayServer";
    private readonly string _root = Directory.GetParent(AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar))?.FullName
        ?? throw new InvalidOperationException("Cannot determine the TablePlay installation directory.");
    private readonly Label _serviceValue = ValueLabel();
    private readonly Label _healthValue = ValueLabel();
    private readonly Label _networkValue = ValueLabel();
    private readonly Label _status = new() { Dock = DockStyle.Fill, TextAlign = ContentAlignment.MiddleLeft, ForeColor = Color.FromArgb(71, 85, 105) };
    private readonly Button _restoreButton = ActionButton("Restore backup");
    private readonly Button _uninstallButton = ActionButton("Uninstall TablePlay", danger: true);
    private readonly System.Windows.Forms.Timer _refreshTimer = new() { Interval = 5000 };
    private string? _pendingRestore;
    private bool _uninstallConfirmed;
    private InstallationConfig _config = new();

    public ManagerForm(bool uninstallMode)
    {
        Text = "TablePlay Server Manager";
        StartPosition = FormStartPosition.CenterScreen;
        Size = new Size(900, 650);
        MinimumSize = new Size(820, 600);
        BackColor = Color.FromArgb(248, 250, 252);
        Font = new Font("Segoe UI", 10F);
        _config = LoadConfiguration();

        var header = new Panel { Dock = DockStyle.Top, Height = 105, BackColor = Color.FromArgb(15, 23, 42), Padding = new Padding(30, 17, 30, 14) };
        header.Controls.Add(new Label { Text = "TABLEPLAY", ForeColor = Color.FromArgb(251, 146, 60), Font = new Font("Segoe UI", 20F, FontStyle.Bold), AutoSize = true, Location = new Point(30, 15) });
        header.Controls.Add(new Label { Text = "Server Manager", ForeColor = Color.FromArgb(203, 213, 225), Font = new Font("Segoe UI", 10.5F), AutoSize = true, Location = new Point(32, 60) });
        var versionBadge = new Label { Text = "VERSION " + _config.ProductVersion, ForeColor = Color.FromArgb(253, 186, 116), BackColor = Color.FromArgb(30, 41, 59), Font = new Font("Segoe UI", 8.5F, FontStyle.Bold), AutoSize = true, Padding = new Padding(10, 6, 10, 6), Anchor = AnchorStyles.Top | AnchorStyles.Right };
        header.Controls.Add(versionBadge);
        void PositionVersionBadge() => versionBadge.Location = new Point(Math.Max(30, header.ClientSize.Width - versionBadge.Width - 30), 28);
        header.Resize += (_, _) => PositionVersionBadge();
        PositionVersionBadge();

        var content = new TableLayoutPanel { Dock = DockStyle.Fill, Padding = new Padding(28, 25, 28, 24), ColumnCount = 3, RowCount = 4 };
        content.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 33.33F));
        content.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 33.33F));
        content.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 33.34F));
        content.RowStyles.Add(new RowStyle(SizeType.Absolute, 130));
        content.RowStyles.Add(new RowStyle(SizeType.Absolute, 230));
        content.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        content.RowStyles.Add(new RowStyle(SizeType.Absolute, 54));

        content.Controls.Add(StatusCard("Windows service", _serviceValue), 0, 0);
        content.Controls.Add(StatusCard("Application health", _healthValue), 1, 0);
        content.Controls.Add(StatusCard("Local network", _networkValue), 2, 0);

        var serviceActions = ActionGroup("Server controls",
            ButtonWithHandler("Start", async () => await ServiceActionAsync("start")),
            ButtonWithHandler("Stop", async () => await ServiceActionAsync("stop")),
            ButtonWithHandler("Restart", RestartAsync),
            ButtonWithHandler("Upgrade / repair", LaunchUpgradeAsync));
        content.Controls.Add(serviceActions, 0, 1);

        var openActions = ActionGroup("Open and download",
            ButtonWithHandler("Open Admin", () => { OpenUrl(AdminUrl); return Task.CompletedTask; }),
            ButtonWithHandler("App QR codes", () => { new DownloadsForm(CurrentIp, _config.ServerPort).ShowDialog(this); return Task.CompletedTask; }),
            ButtonWithHandler("Network Doctor", () => { new NetworkDoctorForm(CurrentIp, _config.ServerPort, 8080).ShowDialog(this); return Task.CompletedTask; }),
            ButtonWithHandler("View logs", () => { OpenFolder(Path.Combine(_root, "logs")); return Task.CompletedTask; }));
        content.Controls.Add(openActions, 1, 1);

        var dataActions = ActionGroup("Data protection",
            ButtonWithHandler("Create backup", BackupAsync),
            _restoreButton,
            ButtonWithHandler("Open backups", () => { OpenFolder(Path.Combine(_root, "backups")); return Task.CompletedTask; }));
        _restoreButton.Click += async (_, _) => await RestoreClickedAsync();
        content.Controls.Add(dataActions, 2, 1);

        var information = new Panel { Dock = DockStyle.Fill, BackColor = Color.White, Padding = new Padding(24), Margin = new Padding(6, 12, 6, 10) };
        information.Controls.Add(new Label
        {
            Dock = DockStyle.Fill,
            Text = "TablePlay runs entirely on this computer and your private restaurant network. The database listens only on 127.0.0.1. Keep regular backups and use the app QR screen to install the latest signed Staff and Customer applications.",
            ForeColor = Color.FromArgb(71, 85, 105),
            Font = new Font("Segoe UI", 11F),
        });
        _uninstallButton.Dock = DockStyle.Bottom;
        _uninstallButton.Height = 42;
        _uninstallButton.Click += async (_, _) => await UninstallClickedAsync();
        information.Controls.Add(_uninstallButton);
        content.Controls.Add(information, 0, 2);
        content.SetColumnSpan(information, 3);

        var statusPanel = new Panel { Dock = DockStyle.Fill, BackColor = Color.FromArgb(241, 245, 249), Padding = new Padding(16, 4, 16, 4), Margin = new Padding(6) };
        statusPanel.Controls.Add(_status);
        content.Controls.Add(statusPanel, 0, 3);
        content.SetColumnSpan(statusPanel, 3);

        Controls.Add(content);
        Controls.Add(header);

        _refreshTimer.Tick += async (_, _) => await RefreshStatusAsync();
        Shown += async (_, _) =>
        {
            await RefreshStatusAsync();
            _refreshTimer.Start();
            if (uninstallMode)
            {
                _uninstallConfirmed = true;
                _uninstallButton.Text = "Confirm uninstall and remove local data";
                SetStatus("Uninstall mode: click the red button to permanently remove TablePlay and its local database.", warning: true);
            }
        };
    }

    private string CurrentIp => FindLocalIPv4Address();
    private string AdminUrl => $"http://{CurrentIp}:{_config.ServerPort}/login";

    private async Task RefreshStatusAsync()
    {
        var running = QueryServiceRunning();
        _serviceValue.Text = running ? "Running" : "Stopped";
        _serviceValue.ForeColor = running ? Color.FromArgb(22, 101, 52) : Color.FromArgb(185, 28, 28);
        _networkValue.Text = $"{CurrentIp}:{_config.ServerPort}";

        try
        {
            using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(2) };
            using var response = await client.GetAsync($"http://127.0.0.1:{_config.ServerPort}/up");
            _healthValue.Text = response.IsSuccessStatusCode ? "Healthy" : "HTTP " + (int)response.StatusCode;
            _healthValue.ForeColor = response.IsSuccessStatusCode ? Color.FromArgb(22, 101, 52) : Color.FromArgb(180, 83, 9);
        }
        catch
        {
            _healthValue.Text = "Offline";
            _healthValue.ForeColor = Color.FromArgb(185, 28, 28);
        }
    }

    private async Task ServiceActionAsync(string action)
    {
        await ExecuteUiActionAsync($"{char.ToUpperInvariant(action[0])}{action[1..]}ing TablePlay…", async () =>
        {
            await Task.Run(() => RunCommand("sc.exe", [action, ServiceName], TimeSpan.FromSeconds(35), action == "stop" ? [0, 1062] : [0, 1056]));
            await Task.Delay(1000);
            await RefreshStatusAsync();
            return "TablePlay service command completed.";
        });
    }

    private async Task RestartAsync()
    {
        await ExecuteUiActionAsync("Restarting TablePlay…", async () =>
        {
            await Task.Run(() =>
            {
                RunCommand("sc.exe", ["stop", ServiceName], TimeSpan.FromSeconds(35), [0, 1062]);
                WaitForServiceState(running: false, TimeSpan.FromSeconds(35));
                RunCommand("sc.exe", ["start", ServiceName], TimeSpan.FromSeconds(35), [0, 1056]);
            });
            await Task.Delay(1500);
            await RefreshStatusAsync();
            return "TablePlay restarted successfully.";
        });
    }

    private Task LaunchUpgradeAsync()
    {
        using var dialog = new OpenFileDialog
        {
            Title = "Select the newer TablePlay Setup file",
            Filter = "TablePlay Setup (TablePlay-Setup*.exe)|TablePlay-Setup*.exe|Windows executable (*.exe)|*.exe",
            CheckFileExists = true,
        };
        if (dialog.ShowDialog(this) != DialogResult.OK) return Task.CompletedTask;

        Process.Start(new ProcessStartInfo(dialog.FileName)
        {
            UseShellExecute = true,
            Verb = "runas",
        });
        SetStatus("Upgrade started. Server Manager will close so application files can be updated safely.", success: true);
        BeginInvoke(Close);
        return Task.CompletedTask;
    }

    private async Task BackupAsync()
    {
        await ExecuteUiActionAsync("Creating a database backup…", async () =>
        {
            var backupDirectory = Path.Combine(_root, "backups");
            Directory.CreateDirectory(backupDirectory);
            var path = Path.Combine(backupDirectory, $"tableplay-{DateTime.Now:yyyyMMdd-HHmmss}.sql");
            var executable = Path.Combine(_root, "database", "bin", "mysqldump.exe");
            await Task.Run(() => RunCommand(executable,
                [$"--defaults-extra-file={Path.Combine(_root, "config", "database-client.ini")}", "--single-transaction", "--routines", "--events", "--hex-blob", $"--result-file={path}", "tableplay"],
                TimeSpan.FromMinutes(10)));
            return "Backup created: " + path;
        });
    }

    private async Task RestoreClickedAsync()
    {
        if (_pendingRestore is null)
        {
            using var dialog = new OpenFileDialog { InitialDirectory = Path.Combine(_root, "backups"), Filter = "TablePlay SQL backup (*.sql)|*.sql", CheckFileExists = true };
            if (dialog.ShowDialog(this) != DialogResult.OK) return;
            _pendingRestore = dialog.FileName;
            _restoreButton.Text = "Confirm restore";
            SetStatus("Selected backup: " + _pendingRestore + ". Click Confirm restore to continue.", warning: true);
            return;
        }

        var backup = _pendingRestore;
        _pendingRestore = null;
        _restoreButton.Text = "Restore backup";
        await ExecuteUiActionAsync("Restoring the selected backup…", async () =>
        {
            var php = Path.Combine(_root, "php", "php.exe");
            var server = Path.Combine(_root, "server");
            await Task.Run(() => RunCommand(php, ["artisan", "down", "--retry=30"], TimeSpan.FromMinutes(1), workingDirectory: server));
            try
            {
                await ImportSqlAsync(backup);
            }
            finally
            {
                await Task.Run(() => RunCommand(php, ["artisan", "up"], TimeSpan.FromMinutes(1), workingDirectory: server));
            }
            return "Backup restored successfully: " + backup;
        });
    }

    private async Task ImportSqlAsync(string path)
    {
        var startInfo = new ProcessStartInfo
        {
            FileName = Path.Combine(_root, "database", "bin", "mysql.exe"),
            WorkingDirectory = Path.Combine(_root, "database", "bin"),
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardInput = true,
            RedirectStandardError = true,
        };
        startInfo.ArgumentList.Add($"--defaults-extra-file={Path.Combine(_root, "config", "database-client.ini")}");
        startInfo.ArgumentList.Add("tableplay");
        using var process = Process.Start(startInfo) ?? throw new InvalidOperationException("Could not start the database restore tool.");
        await using (var file = File.OpenRead(path))
        {
            await file.CopyToAsync(process.StandardInput.BaseStream);
        }
        process.StandardInput.Close();
        await process.WaitForExitAsync().WaitAsync(TimeSpan.FromMinutes(30));
        var error = await process.StandardError.ReadToEndAsync();
        if (process.ExitCode != 0) throw new InvalidOperationException("Restore failed: " + error.Trim());
    }

    private async Task UninstallClickedAsync()
    {
        if (!_uninstallConfirmed)
        {
            _uninstallConfirmed = true;
            _uninstallButton.Text = "Confirm uninstall and remove local data";
            SetStatus("This removes the TablePlay server, database and local configuration. Backups outside the installation folder are not affected. Click again to confirm.", warning: true);
            return;
        }

        await ExecuteUiActionAsync("Stopping services and preparing removal…", async () =>
        {
            var safetyDirectory = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.MyDocuments), "TablePlay Backups");
            Directory.CreateDirectory(safetyDirectory);
            var safetyBackup = Path.Combine(safetyDirectory, $"tableplay-before-uninstall-{DateTime.Now:yyyyMMdd-HHmmss}.sql");
            await Task.Run(() => RunCommand(Path.Combine(_root, "database", "bin", "mysqldump.exe"),
                [$"--defaults-extra-file={Path.Combine(_root, "config", "database-client.ini")}", "--single-transaction", "--routines", "--events", "--triggers", "--hex-blob", $"--result-file={safetyBackup}", "tableplay"],
                TimeSpan.FromMinutes(15)));
            if (!File.Exists(safetyBackup) || new FileInfo(safetyBackup).Length == 0) throw new InvalidOperationException("Safety backup could not be verified. TablePlay was not removed.");
            await Task.Run(() =>
            {
                RunCommand("sc.exe", ["stop", ServiceName], TimeSpan.FromSeconds(35), [0, 1060, 1062]);
                WaitForServiceState(running: false, TimeSpan.FromSeconds(35));
                RunCommand("sc.exe", ["delete", ServiceName], TimeSpan.FromSeconds(20), [0, 1060]);
                RunCommand("netsh.exe", ["advfirewall", "firewall", "delete", "rule", "name=TablePlay Web"], TimeSpan.FromSeconds(15), [0, 1]);
                RunCommand("netsh.exe", ["advfirewall", "firewall", "delete", "rule", "name=TablePlay Realtime"], TimeSpan.FromSeconds(15), [0, 1]);
                Registry.LocalMachine.DeleteSubKeyTree(@"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\TablePlay", throwOnMissingSubKey: false);
                DeleteShortcuts();
            });

            var uninstaller = Path.Combine(_root, "uninstaller", "TablePlay.Uninstaller.exe");
            var temporary = Path.Combine(Path.GetTempPath(), "TablePlay.Uninstaller-" + Guid.NewGuid().ToString("N") + ".exe");
            File.Copy(uninstaller, temporary);
            Process.Start(new ProcessStartInfo(temporary)
            {
                UseShellExecute = true,
                WorkingDirectory = Path.GetTempPath(),
                ArgumentList = { "--root", _root, "--wait-pid", Environment.ProcessId.ToString() },
            });
            BeginInvoke(Close);
            return "Safety backup created at " + safetyBackup + ". TablePlay will be removed after Server Manager closes.";
        });
    }

    private void DeleteShortcuts()
    {
        var paths = new[]
        {
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonDesktopDirectory), "TablePlay Admin.url"),
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonDesktopDirectory), "TablePlay Server Manager.lnk"),
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonPrograms), "TablePlay"),
        };
        foreach (var path in paths)
        {
            if (File.Exists(path)) File.Delete(path);
            else if (Directory.Exists(path)) Directory.Delete(path, recursive: true);
        }
    }

    private async Task ExecuteUiActionAsync(string workingMessage, Func<Task<string>> action)
    {
        SetStatus(workingMessage);
        Enabled = false;
        using var maintenance = new Semaphore(1, 1, @"Global\TablePlayMaintenance");
        var acquired = maintenance.WaitOne(TimeSpan.Zero);
        try
        {
            if (!acquired) throw new InvalidOperationException("Another TablePlay setup, backup, restore, or removal operation is already running.");
            SetStatus(await action(), success: true);
        }
        catch (Exception exception)
        {
            SetStatus(exception.Message, error: true);
        }
        finally
        {
            if (acquired) maintenance.Release();
            Enabled = true;
        }
    }

    private void SetStatus(string message, bool success = false, bool warning = false, bool error = false)
    {
        _status.Text = message;
        _status.ForeColor = error ? Color.FromArgb(185, 28, 28)
            : warning ? Color.FromArgb(180, 83, 9)
            : success ? Color.FromArgb(22, 101, 52)
            : Color.FromArgb(71, 85, 105);
    }

    private bool QueryServiceRunning()
    {
        try
        {
            var output = RunCommand("sc.exe", ["query", ServiceName], TimeSpan.FromSeconds(10), [0, 1060], captureOutput: true);
            return output.Contains("RUNNING", StringComparison.OrdinalIgnoreCase);
        }
        catch { return false; }
    }

    private void WaitForServiceState(bool running, TimeSpan timeout)
    {
        var deadline = DateTimeOffset.UtcNow + timeout;
        while (DateTimeOffset.UtcNow < deadline)
        {
            if (QueryServiceRunning() == running) return;
            Thread.Sleep(500);
        }
    }

    private static string RunCommand(string executable, IReadOnlyList<string> arguments, TimeSpan timeout, IReadOnlyCollection<int>? allowedExitCodes = null, string? workingDirectory = null, bool captureOutput = false)
    {
        allowedExitCodes ??= [0];
        var startInfo = new ProcessStartInfo
        {
            FileName = executable,
            WorkingDirectory = workingDirectory ?? Environment.CurrentDirectory,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
        };
        foreach (var argument in arguments) startInfo.ArgumentList.Add(argument);
        using var process = Process.Start(startInfo) ?? throw new InvalidOperationException("Could not start " + executable + ".");
        var outputTask = process.StandardOutput.ReadToEndAsync();
        var errorTask = process.StandardError.ReadToEndAsync();
        if (!process.WaitForExit((int)timeout.TotalMilliseconds))
        {
            process.Kill(entireProcessTree: true);
            throw new TimeoutException(Path.GetFileName(executable) + " timed out.");
        }
        var output = outputTask.GetAwaiter().GetResult();
        var error = errorTask.GetAwaiter().GetResult();
        if (!allowedExitCodes.Contains(process.ExitCode)) throw new InvalidOperationException(error.Trim().Length > 0 ? error.Trim() : output.Trim());
        return captureOutput ? output + Environment.NewLine + error : string.Empty;
    }

    private InstallationConfig LoadConfiguration()
    {
        var path = Path.Combine(_root, "config", "installation.json");
        return JsonSerializer.Deserialize<InstallationConfig>(File.ReadAllText(path), new JsonSerializerOptions { PropertyNameCaseInsensitive = true }) ?? new InstallationConfig();
    }

    private static string FindLocalIPv4Address()
    {
        return NetworkInterface.GetAllNetworkInterfaces()
            .Where(adapter => adapter.OperationalStatus == OperationalStatus.Up && adapter.NetworkInterfaceType != NetworkInterfaceType.Loopback)
            .OrderByDescending(adapter => adapter.GetIPProperties().GatewayAddresses.Any(gateway => gateway.Address.AddressFamily == AddressFamily.InterNetwork))
            .SelectMany(adapter => adapter.GetIPProperties().UnicastAddresses)
            .FirstOrDefault(address => address.Address.AddressFamily == AddressFamily.InterNetwork && !IPAddress.IsLoopback(address.Address))?.Address.ToString()
            ?? "127.0.0.1";
    }

    private static void OpenUrl(string url) => Process.Start(new ProcessStartInfo(url) { UseShellExecute = true });
    private static void OpenFolder(string path) { Directory.CreateDirectory(path); Process.Start(new ProcessStartInfo("explorer.exe", path) { UseShellExecute = true }); }

    private static Panel StatusCard(string title, Label value)
    {
        var card = new Panel { Dock = DockStyle.Fill, BackColor = Color.White, Padding = new Padding(20), Margin = new Padding(6) };
        card.Controls.Add(value);
        card.Controls.Add(new Label { Text = title.ToUpperInvariant(), Dock = DockStyle.Top, Height = 28, ForeColor = Color.FromArgb(100, 116, 139), Font = new Font("Segoe UI", 8.5F, FontStyle.Bold) });
        return card;
    }

    private static Label ValueLabel() => new() { Text = "Checking…", Dock = DockStyle.Fill, TextAlign = ContentAlignment.MiddleLeft, Font = new Font("Segoe UI", 16F, FontStyle.Bold), ForeColor = Color.FromArgb(15, 23, 42), Padding = new Padding(0, 20, 0, 0) };

    private static Panel ActionGroup(string title, params Button[] buttons)
    {
        var panel = new Panel { Dock = DockStyle.Fill, BackColor = Color.White, Padding = new Padding(20, 16, 20, 16), Margin = new Padding(6, 12, 6, 6) };
        var layout = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, RowCount = buttons.Length + 1, Margin = Padding.Empty, Padding = Padding.Empty };
        layout.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        layout.RowStyles.Add(new RowStyle(SizeType.Absolute, 42));
        layout.Controls.Add(new Label { Text = title, Dock = DockStyle.Fill, TextAlign = ContentAlignment.MiddleLeft, ForeColor = Color.FromArgb(15, 23, 42), Font = new Font("Segoe UI", 10F, FontStyle.Bold), Margin = Padding.Empty }, 0, 0);
        for (var index = 0; index < buttons.Length; index++)
        {
            layout.RowStyles.Add(new RowStyle(SizeType.Percent, 100F / buttons.Length));
            var button = buttons[index];
            button.Dock = DockStyle.Fill;
            button.Margin = new Padding(0, 3, 0, 3);
            button.MinimumSize = new Size(0, 34);
            layout.Controls.Add(button, 0, index + 1);
        }
        panel.Controls.Add(layout);
        return panel;
    }

    private Button ButtonWithHandler(string text, Func<Task> handler)
    {
        var button = ActionButton(text);
        button.Click += async (_, _) => await handler();
        return button;
    }

    private static Button ActionButton(string text, bool danger = false)
    {
        var button = new Button { Text = text, FlatStyle = FlatStyle.Flat, BackColor = Color.White, ForeColor = danger ? Color.FromArgb(185, 28, 28) : Color.FromArgb(30, 41, 59), TextAlign = ContentAlignment.MiddleLeft, Padding = new Padding(10, 0, 0, 0) };
        button.FlatAppearance.BorderColor = danger ? Color.FromArgb(254, 202, 202) : Color.FromArgb(226, 232, 240);
        return button;
    }

    private sealed class InstallationConfig
    {
        public int ServerPort { get; set; } = 8000;
        public string ProductVersion { get; set; } = "2.0.0";
    }
}

internal sealed class DownloadsForm : Form
{
    public DownloadsForm(string localIp, int serverPort)
    {
        Text = "TablePlay App Downloads";
        StartPosition = FormStartPosition.CenterParent;
        Size = new Size(850, 440);
        BackColor = Color.White;
        Font = new Font("Segoe UI", 10F);
        var flow = new FlowLayoutPanel { Dock = DockStyle.Fill, Padding = new Padding(22), FlowDirection = FlowDirection.LeftToRight, WrapContents = false, AutoScroll = true };
        flow.Controls.Add(QrCard("Staff Android", $"http://{localIp}:{serverPort}/api/v1/app-updates/download/staff-android"));
        flow.Controls.Add(QrCard("Customer Android", $"http://{localIp}:{serverPort}/api/v1/app-updates/download/customer-android"));
        flow.Controls.Add(QrCard("Staff Windows", $"http://{localIp}:{serverPort}/api/v1/app-updates/download/staff-windows"));
        Controls.Add(flow);
    }

    private static Panel QrCard(string title, string url)
    {
        using var generator = new QRCodeGenerator();
        using var data = generator.CreateQrCode(url, QRCodeGenerator.ECCLevel.Q);
        using var code = new PngByteQRCode(data);
        using var stream = new MemoryStream(code.GetGraphic(7));
        var image = Image.FromStream(stream);

        var panel = new Panel { Width = 250, Height = 350, BackColor = Color.FromArgb(248, 250, 252), Padding = new Padding(16), Margin = new Padding(8) };
        var picture = new PictureBox { Image = new Bitmap(image), SizeMode = PictureBoxSizeMode.Zoom, Dock = DockStyle.Top, Height = 210 };
        var label = new Label { Text = title, Dock = DockStyle.Top, Height = 34, TextAlign = ContentAlignment.MiddleCenter, Font = new Font("Segoe UI", 11F, FontStyle.Bold), ForeColor = Color.FromArgb(15, 23, 42) };
        var link = new LinkLabel { Text = url, Dock = DockStyle.Fill, TextAlign = ContentAlignment.TopCenter, LinkColor = Color.FromArgb(234, 88, 12), AutoEllipsis = true };
        link.LinkClicked += (_, _) => Process.Start(new ProcessStartInfo(url) { UseShellExecute = true });
        panel.Controls.Add(link);
        panel.Controls.Add(label);
        panel.Controls.Add(picture);
        return panel;
    }
}
