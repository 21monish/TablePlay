using System.Diagnostics;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Text;

namespace TablePlay.ServerManager;

internal sealed class NetworkDoctorForm : Form
{
    private readonly string _ip;
    private readonly int _serverPort;
    private readonly int _reverbPort;
    private readonly TextBox _result = new()
    {
        Dock = DockStyle.Fill,
        Multiline = true,
        ReadOnly = true,
        ScrollBars = ScrollBars.Vertical,
        Font = new Font("Consolas", 10F),
        BackColor = Color.White,
        BorderStyle = BorderStyle.FixedSingle,
    };
    private readonly Label _status = new()
    {
        Dock = DockStyle.Fill,
        TextAlign = ContentAlignment.MiddleLeft,
        ForeColor = Color.FromArgb(71, 85, 105),
    };

    public NetworkDoctorForm(string ip, int serverPort, int reverbPort)
    {
        _ip = ip;
        _serverPort = serverPort;
        _reverbPort = reverbPort;
        Text = "TablePlay Network Doctor";
        StartPosition = FormStartPosition.CenterParent;
        Size = new Size(820, 650);
        MinimumSize = new Size(720, 560);
        BackColor = Color.FromArgb(248, 250, 252);
        Font = new Font("Segoe UI", 10F);

        var header = new Panel
        {
            Dock = DockStyle.Top,
            Height = 100,
            BackColor = Color.FromArgb(15, 23, 42),
            Padding = new Padding(28, 18, 28, 12),
        };
        header.Controls.Add(new Label
        {
            Text = "NETWORK DOCTOR",
            AutoSize = true,
            ForeColor = Color.FromArgb(251, 146, 60),
            Font = new Font("Segoe UI", 18F, FontStyle.Bold),
            Location = new Point(27, 16),
        });
        header.Controls.Add(new Label
        {
            Text = "Safe local-network checks for Staff and Customer devices",
            AutoSize = true,
            ForeColor = Color.FromArgb(203, 213, 225),
            Location = new Point(30, 59),
        });

        var buttons = new FlowLayoutPanel
        {
            Dock = DockStyle.Top,
            Height = 58,
            Padding = new Padding(18, 10, 18, 8),
            BackColor = Color.White,
        };
        buttons.Controls.Add(Button("Run checks", async () => await RefreshAsync()));
        buttons.Controls.Add(Button("Fix Local Network Access", FixAsync, accent: true));
        buttons.Controls.Add(Button("Copy diagnostic", () =>
        {
            Clipboard.SetText(_result.Text);
            _status.Text = "Sanitized diagnostic copied. It contains no passwords, tokens, licence secrets or customer data.";
            return Task.CompletedTask;
        }));

        var note = new Label
        {
            Dock = DockStyle.Bottom,
            Height = 66,
            Padding = new Padding(20, 8, 20, 8),
            Text = "Database port 3310 remains localhost-only and is never opened. For a permanent restaurant installation, use a dedicated router and reserve this laptop's IPv4 address in DHCP.",
            ForeColor = Color.FromArgb(71, 85, 105),
            BackColor = Color.White,
        };
        var statusPanel = new Panel
        {
            Dock = DockStyle.Bottom,
            Height = 46,
            Padding = new Padding(18, 4, 18, 4),
            BackColor = Color.FromArgb(241, 245, 249),
        };
        statusPanel.Controls.Add(_status);

        Controls.Add(_result);
        Controls.Add(note);
        Controls.Add(statusPanel);
        Controls.Add(buttons);
        Controls.Add(header);
        Shown += async (_, _) => await RefreshAsync();
    }

    private async Task RefreshAsync()
    {
        UseWaitCursor = true;
        _status.Text = "Checking the restaurant network…";
        try
        {
            var adapter = ActiveAdapter();
            var category = adapter is null ? "Unknown" : await NetworkCategoryAsync(adapter.Name);
            var webPort = await PortOpenAsync(_serverPort);
            var realtimePort = await PortOpenAsync(_reverbPort);
            var health = await HttpCheckAsync($"http://127.0.0.1:{_serverPort}/up");
            var branding = await HttpCheckAsync($"http://127.0.0.1:{_serverPort}/api/v1/branding");
            var gateway = adapter?.GetIPProperties().GatewayAddresses
                .FirstOrDefault(value => value.Address.AddressFamily == AddressFamily.InterNetwork)?.Address.ToString()
                ?? "Not detected";

            var output = new StringBuilder()
                .AppendLine("TABLEPLAY NETWORK DIAGNOSTIC")
                .AppendLine($"Generated: {DateTimeOffset.Now:yyyy-MM-dd HH:mm:ss zzz}")
                .AppendLine()
                .AppendLine($"Adapter:          {adapter?.Name ?? "Not detected"}")
                .AppendLine($"Network category: {category}")
                .AppendLine($"IPv4 address:     {_ip}")
                .AppendLine($"Gateway:          {gateway}")
                .AppendLine($"Admin URL:        http://{_ip}:{_serverPort}/login")
                .AppendLine()
                .AppendLine($"HTTP port {_serverPort}:   {Pass(webPort)}")
                .AppendLine($"Reverb port {_reverbPort}: {Pass(realtimePort)}")
                .AppendLine($"Laravel health:   {health}")
                .AppendLine($"Branding API:     {branding}")
                .AppendLine("Database 3310:    Localhost-only (protected)")
                .AppendLine()
                .AppendLine(category.Equals("Public", StringComparison.OrdinalIgnoreCase)
                    ? "ACTION: Windows marks this network Public. Use Fix Local Network Access, then reconnect the apps."
                    : "Windows profile is not reported as Public.")
                .AppendLine(!webPort || !health.StartsWith("PASS", StringComparison.Ordinal)
                    ? "ACTION: Start or restart TablePlay before connecting apps."
                    : "The local TablePlay web service is responding.")
                .AppendLine("If these checks pass but phones still time out, the hotspot/router is probably isolating connected devices. Enable client/device communication, or use a dedicated restaurant router.")
                .AppendLine()
                .AppendLine("This report intentionally excludes credentials, tokens, licence material and customer data.");
            _result.Text = output.ToString();
            _status.Text = webPort && health.StartsWith("PASS", StringComparison.Ordinal)
                ? "Local server checks completed. Test the displayed Admin URL from a phone on the same Wi-Fi."
                : "One or more local checks failed. Follow the ACTION lines above.";
        }
        catch (Exception exception)
        {
            _result.Text = "Network Doctor could not complete: " + exception.Message;
            _status.Text = "Network check needs attention.";
        }
        finally
        {
            UseWaitCursor = false;
        }
    }

    private async Task FixAsync()
    {
        var adapter = ActiveAdapter() ?? throw new InvalidOperationException("No active IPv4 restaurant adapter was found.");
        var escapedAlias = adapter.Name.Replace("'", "''", StringComparison.Ordinal);
        var command = string.Join("; ",
            $"Set-NetConnectionProfile -InterfaceAlias '{escapedAlias}' -NetworkCategory Private",
            "netsh advfirewall firewall delete rule name='TablePlay Web' | Out-Null",
            $"netsh advfirewall firewall add rule name='TablePlay Web' dir=in action=allow protocol=TCP localport={_serverPort} profile=private",
            "netsh advfirewall firewall delete rule name='TablePlay Realtime' | Out-Null",
            $"netsh advfirewall firewall add rule name='TablePlay Realtime' dir=in action=allow protocol=TCP localport={_reverbPort} profile=private");
        using var process = Process.Start(new ProcessStartInfo("powershell.exe")
        {
            UseShellExecute = true,
            Verb = "runas",
            WindowStyle = ProcessWindowStyle.Hidden,
            ArgumentList = { "-NoProfile", "-ExecutionPolicy", "Bypass", "-Command", command },
        }) ?? throw new InvalidOperationException("Windows did not start the network repair.");
        await process.WaitForExitAsync();
        if (process.ExitCode != 0) throw new InvalidOperationException("Network repair was cancelled or Windows rejected the change.");
        _status.Text = "Private profile and TablePlay firewall rules repaired. Running checks again…";
        await RefreshAsync();
    }

    private NetworkInterface? ActiveAdapter() => NetworkInterface.GetAllNetworkInterfaces()
        .Where(value => value.OperationalStatus == OperationalStatus.Up && value.NetworkInterfaceType != NetworkInterfaceType.Loopback)
        .FirstOrDefault(value => value.GetIPProperties().UnicastAddresses.Any(address => address.Address.ToString() == _ip))
        ?? NetworkInterface.GetAllNetworkInterfaces()
            .Where(value => value.OperationalStatus == OperationalStatus.Up && value.NetworkInterfaceType != NetworkInterfaceType.Loopback)
            .OrderByDescending(value => value.GetIPProperties().GatewayAddresses.Any(address => address.Address.AddressFamily == AddressFamily.InterNetwork))
            .FirstOrDefault();

    private static async Task<string> NetworkCategoryAsync(string alias)
    {
        var safe = alias.Replace("'", "''", StringComparison.Ordinal);
        return (await CaptureAsync("powershell.exe", ["-NoProfile", "-Command", $"(Get-NetConnectionProfile -InterfaceAlias '{safe}' -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty NetworkCategory)"]))
            .Trim() is { Length: > 0 } value ? value : "Unknown";
    }

    private static async Task<bool> PortOpenAsync(int port)
    {
        try
        {
            using var client = new TcpClient();
            await client.ConnectAsync("127.0.0.1", port).WaitAsync(TimeSpan.FromSeconds(2));
            return true;
        }
        catch { return false; }
    }

    private static async Task<string> HttpCheckAsync(string url)
    {
        try
        {
            using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(3) };
            using var response = await client.GetAsync(url);
            return response.IsSuccessStatusCode ? "PASS" : $"FAIL (HTTP {(int)response.StatusCode})";
        }
        catch (Exception exception) { return "FAIL (" + exception.GetType().Name + ")"; }
    }

    private static async Task<string> CaptureAsync(string executable, IReadOnlyList<string> arguments)
    {
        var start = new ProcessStartInfo(executable)
        {
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
        };
        foreach (var argument in arguments) start.ArgumentList.Add(argument);
        using var process = Process.Start(start) ?? throw new InvalidOperationException("Could not start network inspection.");
        var output = await process.StandardOutput.ReadToEndAsync();
        await process.WaitForExitAsync().WaitAsync(TimeSpan.FromSeconds(8));
        return output;
    }

    private static string Pass(bool value) => value ? "PASS" : "FAIL";

    private static Button Button(string text, Func<Task> action, bool accent = false)
    {
        var button = new Button
        {
            Text = text,
            AutoSize = true,
            Height = 36,
            Padding = new Padding(12, 0, 12, 0),
            Margin = new Padding(4),
            FlatStyle = FlatStyle.Flat,
            BackColor = accent ? Color.FromArgb(234, 88, 12) : Color.White,
            ForeColor = accent ? Color.White : Color.FromArgb(30, 41, 59),
        };
        button.FlatAppearance.BorderColor = accent ? Color.FromArgb(234, 88, 12) : Color.FromArgb(203, 213, 225);
        button.Click += async (_, _) =>
        {
            button.Enabled = false;
            try { await action(); }
            catch (Exception exception) { MessageBox.Show(exception.Message, "Network Doctor", MessageBoxButtons.OK, MessageBoxIcon.Warning); }
            finally { button.Enabled = true; }
        };
        return button;
    }
}
