using System.Diagnostics;
using System.Drawing;

namespace TablePlay.Setup;

internal sealed class SetupForm : Form
{
    private readonly TextBox _restaurantName = new() { PlaceholderText = "Restaurant name" };
    private readonly TextBox _adminEmail = new() { Text = "admin@tableplay.local" };
    private readonly TextBox _adminPassword = new() { UseSystemPasswordChar = true, PlaceholderText = "At least 10 characters" };
    private readonly CheckBox _showPassword = new() { Text = "Show password", AutoSize = true };
    private readonly Button _installButton = new() { Text = "Install TablePlay", Height = 44 };
    private readonly Button _launchButton = new() { Text = "Launch TablePlay", Height = 44, Enabled = false };
    private readonly ProgressBar _progress = new() { Minimum = 0, Maximum = 100, Height = 8, Style = ProgressBarStyle.Continuous };
    private readonly Label _status = new() { AutoSize = false, Height = 48, Text = "Ready to install", ForeColor = Color.FromArgb(71, 85, 105) };
    private readonly TextBox _log = new() { Multiline = true, ReadOnly = true, ScrollBars = ScrollBars.Vertical, BackColor = Color.FromArgb(248, 250, 252), BorderStyle = BorderStyle.FixedSingle };
    private string? _installedUrl;
    private readonly bool _upgradeMode;
    private readonly InstallationState _installationState;

    public SetupForm()
    {
        var installRoot = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "TablePlay");
        _installationState = InstallerEngine.GetInstallationState(installRoot);
        _upgradeMode = _installationState == InstallationState.Repair;
        Text = "TablePlay Setup";
        StartPosition = FormStartPosition.CenterScreen;
        MinimumSize = new Size(790, 640);
        Size = new Size(790, 640);
        BackColor = Color.White;
        Font = new Font("Segoe UI", 10F);

        var header = new Panel { Dock = DockStyle.Top, Height = 112, BackColor = Color.FromArgb(15, 23, 42), Padding = new Padding(30, 18, 30, 16) };
        var title = new Label { Text = "TABLEPLAY", ForeColor = Color.FromArgb(251, 146, 60), Font = new Font("Segoe UI", 21F, FontStyle.Bold), AutoSize = true, Location = new Point(30, 16) };
        var subtitle = new Label { Text = "One-click local restaurant server", ForeColor = Color.FromArgb(203, 213, 225), Font = new Font("Segoe UI", 10.5F), AutoSize = true, Location = new Point(32, 62) };
        header.Controls.Add(title);
        header.Controls.Add(subtitle);

        var content = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            Padding = new Padding(30, 24, 30, 24),
            ColumnCount = 2,
            RowCount = 9,
        };
        content.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 50));
        content.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 50));
        content.RowStyles.Add(new RowStyle(SizeType.Absolute, 26));
        content.RowStyles.Add(new RowStyle(SizeType.Absolute, 44));
        content.RowStyles.Add(new RowStyle(SizeType.Absolute, 26));
        content.RowStyles.Add(new RowStyle(SizeType.Absolute, 44));
        content.RowStyles.Add(new RowStyle(SizeType.Absolute, 26));
        content.RowStyles.Add(new RowStyle(SizeType.Absolute, 48));
        content.RowStyles.Add(new RowStyle(SizeType.Absolute, 18));
        content.RowStyles.Add(new RowStyle(SizeType.Absolute, 58));
        content.RowStyles.Add(new RowStyle(SizeType.Percent, 100));

        AddLabel(content, "Restaurant name", 0, 0, 2);
        _restaurantName.Dock = DockStyle.Fill;
        content.Controls.Add(_restaurantName, 0, 1);
        content.SetColumnSpan(_restaurantName, 2);

        AddLabel(content, "Administrator email", 0, 2, 1);
        AddLabel(content, "Administrator password", 1, 2, 1);
        _adminEmail.Dock = DockStyle.Fill;
        _adminPassword.Dock = DockStyle.Fill;
        _adminEmail.Margin = new Padding(0, 0, 8, 0);
        _adminPassword.Margin = new Padding(8, 0, 0, 0);
        content.Controls.Add(_adminEmail, 0, 3);
        content.Controls.Add(_adminPassword, 1, 3);

        _showPassword.Margin = new Padding(8, 4, 0, 0);
        content.Controls.Add(_showPassword, 1, 4);
        content.Controls.Add(_progress, 0, 5);
        content.SetColumnSpan(_progress, 2);
        _progress.Dock = DockStyle.Bottom;
        content.Controls.Add(_status, 0, 5);
        content.SetColumnSpan(_status, 2);
        _status.Dock = DockStyle.Top;

        _installButton.Dock = DockStyle.Fill;
        _launchButton.Dock = DockStyle.Fill;
        _installButton.Margin = new Padding(0, 7, 8, 7);
        _launchButton.Margin = new Padding(8, 7, 0, 7);
        StylePrimaryButton(_installButton);
        StyleSecondaryButton(_launchButton);
        content.Controls.Add(_installButton, 0, 7);
        content.Controls.Add(_launchButton, 1, 7);

        _log.Dock = DockStyle.Fill;
        content.Controls.Add(_log, 0, 8);
        content.SetColumnSpan(_log, 2);

        Controls.Add(content);
        Controls.Add(header);

        _showPassword.CheckedChanged += (_, _) => _adminPassword.UseSystemPasswordChar = !_showPassword.Checked;
        _installButton.Click += InstallClicked;
        _launchButton.Click += (_, _) => LaunchInstalledUrl();

        if (_upgradeMode)
        {
            subtitle.Text = "Safe in-place upgrade and repair";
            _restaurantName.Text = "Existing restaurant configuration will be preserved";
            _restaurantName.Enabled = false;
            _adminEmail.Text = "Existing administrator account will be preserved";
            _adminEmail.Enabled = false;
            _adminPassword.Text = "configuration-preserved";
            _adminPassword.Enabled = false;
            _showPassword.Enabled = false;
            _installButton.Text = "Upgrade / Repair TablePlay";
            _status.Text = "Ready to back up and upgrade the existing installation";
        }
        else if (_installationState == InstallationState.IncompleteFresh)
        {
            subtitle.Text = "Safely restart an interrupted installation";
            _installButton.Text = "Restart TablePlay Installation";
            _status.Text = "Interrupted files will be preserved in Recovery before setup restarts";
        }
    }

    private async void InstallClicked(object? sender, EventArgs eventArgs)
    {
        var restaurant = _restaurantName.Text.Trim();
        var email = _adminEmail.Text.Trim();
        var password = _adminPassword.Text;

        if (!_upgradeMode && restaurant.Length is < 2 or > 255)
        {
            ShowInlineError("Enter a restaurant name between 2 and 255 characters.");
            return;
        }
        if (!_upgradeMode && password.Length < 10)
        {
            ShowInlineError("Use an administrator password with at least 10 characters.");
            return;
        }
        if (!_upgradeMode && (!email.Contains('@') || email.Length > 255))
        {
            ShowInlineError("Enter a valid administrator email address.");
            return;
        }

        SetInputEnabled(false);
        _log.Clear();
        _status.ForeColor = Color.FromArgb(71, 85, 105);

        var progress = new Progress<InstallProgress>(update =>
        {
            _progress.Value = Math.Clamp(update.Percent, 0, 100);
            _status.Text = update.Message;
            if (!string.IsNullOrWhiteSpace(update.Detail))
            {
                _log.AppendText(update.Detail.TrimEnd() + Environment.NewLine);
            }
        });

        try
        {
            var result = await InstallerEngine.InstallAsync(new InstallOptions(restaurant, email, password), progress);
            _installedUrl = result.AdminUrl;
            _progress.Value = 100;
            _status.Text = $"{(_upgradeMode ? "Upgrade" : "Installation")} complete — server {result.LocalIp}:{result.ServerPort}";
            _status.ForeColor = Color.FromArgb(22, 101, 52);
            _launchButton.Enabled = true;
            _installButton.Text = _upgradeMode ? "Upgraded" : "Installed";
        }
        catch (Exception exception)
        {
            _progress.Value = 0;
            _status.Text = (_upgradeMode ? "Upgrade" : "Installation") + " stopped: " + exception.Message;
            _status.ForeColor = Color.FromArgb(185, 28, 28);
            _log.AppendText(exception + Environment.NewLine);
            SetInputEnabled(true);
        }
    }

    private void LaunchInstalledUrl()
    {
        if (_installedUrl is null)
        {
            return;
        }

        Process.Start(new ProcessStartInfo(_installedUrl) { UseShellExecute = true });
    }

    private void SetInputEnabled(bool enabled)
    {
        _restaurantName.Enabled = enabled;
        _adminEmail.Enabled = enabled;
        _adminPassword.Enabled = enabled;
        _showPassword.Enabled = enabled;
        _installButton.Enabled = enabled;
    }

    private void ShowInlineError(string message)
    {
        _status.Text = message;
        _status.ForeColor = Color.FromArgb(185, 28, 28);
    }

    private static void AddLabel(TableLayoutPanel panel, string text, int column, int row, int span)
    {
        var label = new Label { Text = text, AutoSize = true, ForeColor = Color.FromArgb(51, 65, 85), Font = new Font("Segoe UI", 9F, FontStyle.Bold) };
        label.Margin = new Padding(column == 0 ? 0 : 8, 3, 0, 0);
        panel.Controls.Add(label, column, row);
        panel.SetColumnSpan(label, span);
    }

    private static void StylePrimaryButton(Button button)
    {
        button.FlatStyle = FlatStyle.Flat;
        button.FlatAppearance.BorderSize = 0;
        button.BackColor = Color.FromArgb(234, 88, 12);
        button.ForeColor = Color.White;
        button.Font = new Font("Segoe UI", 10F, FontStyle.Bold);
    }

    private static void StyleSecondaryButton(Button button)
    {
        button.FlatStyle = FlatStyle.Flat;
        button.FlatAppearance.BorderColor = Color.FromArgb(203, 213, 225);
        button.BackColor = Color.White;
        button.ForeColor = Color.FromArgb(15, 23, 42);
        button.Font = new Font("Segoe UI", 10F, FontStyle.Bold);
    }
}

internal sealed record InstallOptions(string RestaurantName, string AdminEmail, string AdminPassword);
internal sealed record InstallResult(string AdminUrl, string LocalIp, int ServerPort);
internal sealed record InstallProgress(int Percent, string Message, string? Detail = null);
