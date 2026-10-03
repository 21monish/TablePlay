using System.Diagnostics;
using System.Drawing;
using System.Runtime.InteropServices;

namespace TablePlay.Uninstaller;

internal static class Program
{
    [STAThread]
    private static int Main(string[] args)
    {
        ApplicationConfiguration.Initialize();
        var root = ValueAfter(args, "--root");
        var pidText = ValueAfter(args, "--wait-pid");
        var expectedRoot = Path.GetFullPath(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "TablePlay"));
        if (root is null || !Path.GetFullPath(root).Equals(expectedRoot, StringComparison.OrdinalIgnoreCase)) return 2;
        int.TryParse(pidText, out var pid);
        using var form = new UninstallProgressForm(expectedRoot, pid);
        Application.Run(form);
        return form.Succeeded ? 0 : 1;
    }

    private static string? ValueAfter(string[] args, string name)
    {
        var index = Array.FindIndex(args, argument => argument.Equals(name, StringComparison.OrdinalIgnoreCase));
        return index >= 0 && index + 1 < args.Length ? args[index + 1] : null;
    }
}

internal sealed class UninstallProgressForm : Form
{
    private const int MoveFileDelayUntilReboot = 0x4;
    private const string ServiceName = "TablePlayServer";
    private readonly string _root;
    private readonly int _waitPid;
    private readonly Label _title = new() { AutoSize = true, Font = new Font("Segoe UI", 20F, FontStyle.Bold), ForeColor = Color.White };
    private readonly Label _status = new() { Dock = DockStyle.Top, Height = 55, Font = new Font("Segoe UI", 11F), ForeColor = Color.FromArgb(71, 85, 105) };
    private readonly ProgressBar _progress = new() { Dock = DockStyle.Top, Height = 9, Style = ProgressBarStyle.Marquee, MarqueeAnimationSpeed = 24 };
    private readonly Button _close = new() { Text = "Close", Dock = DockStyle.Bottom, Height = 42, Enabled = false };
    public bool Succeeded { get; private set; }

    public UninstallProgressForm(string root, int waitPid)
    {
        _root = root;
        _waitPid = waitPid;
        Text = "Remove TablePlay";
        StartPosition = FormStartPosition.CenterScreen;
        ClientSize = new Size(620, 330);
        MinimumSize = new Size(620, 330);
        MaximizeBox = false;
        BackColor = Color.White;
        Font = new Font("Segoe UI", 10F);

        var header = new Panel { Dock = DockStyle.Top, Height = 112, BackColor = Color.FromArgb(15, 23, 42), Padding = new Padding(28, 18, 28, 12) };
        _title.Text = "Removing TablePlay";
        _title.Location = new Point(27, 18);
        header.Controls.Add(_title);
        header.Controls.Add(new Label { Text = "Server, private database and local configuration", AutoSize = true, Location = new Point(30, 63), ForeColor = Color.FromArgb(203, 213, 225) });

        var body = new Panel { Dock = DockStyle.Fill, Padding = new Padding(30, 28, 30, 24) };
        _status.Text = "Waiting for TablePlay Server Manager to close…";
        _close.FlatStyle = FlatStyle.Flat;
        _close.FlatAppearance.BorderSize = 0;
        _close.BackColor = Color.FromArgb(234, 88, 12);
        _close.ForeColor = Color.White;
        _close.Font = new Font("Segoe UI", 10F, FontStyle.Bold);
        _close.Click += (_, _) => Close();
        body.Controls.Add(_close);
        body.Controls.Add(_progress);
        body.Controls.Add(_status);
        Controls.Add(body);
        Controls.Add(header);
        FormClosing += (_, e) => { if (!_close.Enabled) e.Cancel = true; };
        Shown += async (_, _) => await RemoveAsync();
    }

    private async Task RemoveAsync()
    {
        using var maintenance = new Semaphore(1, 1, @"Global\TablePlayMaintenance");
        var acquired = maintenance.WaitOne(TimeSpan.FromSeconds(30));
        try
        {
            if (!acquired) throw new InvalidOperationException("Another TablePlay setup, backup, restore, or removal operation is still running.");
            if (_waitPid > 0) await Task.Run(() => { try { Process.GetProcessById(_waitPid).WaitForExit(30000); } catch { } });
            _status.Text = "Stopping TablePlay services safely…";
            await Task.Run(() => StopServiceAndOwnedProcesses(_root));
            _status.Text = "Removing application files and private data…";
            await Task.Run(() =>
            {
                for (var attempt = 0; attempt < 10; attempt++)
                {
                    try { DeleteDirectorySafely(_root); return; }
                    catch when (attempt < 9) { Thread.Sleep(1000); }
                }
            });
            if (Directory.Exists(_root)) throw new IOException("Some TablePlay files are still in use. Restart Windows, then run uninstall again.");
            Succeeded = true;
            _title.Text = "TablePlay removed";
            _status.Text = "The local TablePlay server and its data were removed successfully.";
            _progress.Style = ProgressBarStyle.Continuous;
            _progress.Value = 100;
            _progress.MarqueeAnimationSpeed = 0;
            MoveFileEx(Environment.ProcessPath!, null, MoveFileDelayUntilReboot);
        }
        catch (Exception exception)
        {
            _title.Text = "Removal needs attention";
            _status.Text = exception.Message;
            _status.ForeColor = Color.FromArgb(185, 28, 28);
            _progress.Style = ProgressBarStyle.Continuous;
            _progress.Value = 0;
        }
        finally
        {
            if (acquired) maintenance.Release();
            _close.Enabled = true;
            _close.Text = Succeeded ? "Finish" : "Close";
        }
    }

    private static void DeleteDirectorySafely(string path)
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

    private static void StopServiceAndOwnedProcesses(string root)
    {
        RunServiceCommand("stop", [0, 1060, 1062]);
        var deadline = DateTimeOffset.UtcNow + TimeSpan.FromSeconds(60);
        while (DateTimeOffset.UtcNow < deadline)
        {
            var state = RunServiceCommand("query", [0, 1060], captureOutput: true);
            if (!state.Contains("RUNNING", StringComparison.OrdinalIgnoreCase)
                && !state.Contains("STOP_PENDING", StringComparison.OrdinalIgnoreCase)) break;
            Thread.Sleep(500);
        }

        var expected = Path.GetFullPath(root).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
        var failures = new List<string>();
        foreach (var process in Process.GetProcesses())
        {
            using (process)
            {
                if (process.Id == Environment.ProcessId) continue;
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
            throw new IOException("These TablePlay processes could not be stopped: " + string.Join(", ", failures) + ". Restart Windows and run uninstall again.");
        RunServiceCommand("delete", [0, 1060, 1072]);
    }

    private static string RunServiceCommand(string action, int[] allowedExitCodes, bool captureOutput = false)
    {
        using var process = new Process
        {
            StartInfo = new ProcessStartInfo("sc.exe")
            {
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
            },
        };
        process.StartInfo.ArgumentList.Add(action);
        process.StartInfo.ArgumentList.Add(ServiceName);
        process.Start();
        var stdout = process.StandardOutput.ReadToEndAsync();
        var stderr = process.StandardError.ReadToEndAsync();
        if (!process.WaitForExit(30000))
        {
            try { process.Kill(entireProcessTree: true); } catch { }
            throw new TimeoutException("Windows did not complete the TablePlay service " + action + " command.");
        }
        Task.WaitAll(stdout, stderr);
        var output = (stdout.Result + Environment.NewLine + stderr.Result).Trim();
        if (!allowedExitCodes.Contains(process.ExitCode))
            throw new InvalidOperationException($"TablePlay service {action} failed with exit code {process.ExitCode}: {output}");
        return captureOutput ? output : string.Empty;
    }

    [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool MoveFileEx(string existingFileName, string? newFileName, int flags);
}
