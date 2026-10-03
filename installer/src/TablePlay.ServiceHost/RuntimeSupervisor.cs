using System.Diagnostics;
using System.Net.Sockets;
using System.Text.Json;

namespace TablePlay.ServiceHost;

internal sealed class RuntimeSupervisor
{
    private readonly string _root = Directory.GetParent(AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar))?.FullName
        ?? throw new InvalidOperationException("Cannot determine the TablePlay installation directory.");
    private readonly Dictionary<string, ManagedChild> _children = new(StringComparer.OrdinalIgnoreCase);
    private readonly Dictionary<string, Queue<DateTimeOffset>> _restartHistory = new(StringComparer.OrdinalIgnoreCase);
    private readonly object _logLock = new();
    private InstallationConfig _config = new();
    private string _logDirectory = string.Empty;

    public async Task RunAsync(CancellationToken cancellationToken, Action? onReady = null)
    {
        using var instance = new Mutex(initiallyOwned: true, name: @"Global\TablePlayServerHost", createdNew: out var isFirstInstance);
        if (!isFirstInstance)
        {
            throw new InvalidOperationException("Another TablePlay runtime host is already running.");
        }

        _config = LoadConfiguration();
        _logDirectory = Path.Combine(_root, "logs");
        Directory.CreateDirectory(_logDirectory);
        Directory.CreateDirectory(Path.Combine(_root, "backups"));

        try
        {
            Log("runtime", $"Starting TablePlay from {_root}.");
            StartDatabase();
            await WaitForManagedPortAsync("database", _config.DatabasePort, TimeSpan.FromSeconds(60), cancellationToken);
            StartApplicationProcesses();
            await WaitForManagedPortAsync("laravel", _config.ServerPort, TimeSpan.FromSeconds(90), cancellationToken);
            await WaitForManagedPortAsync("reverb", _config.ReverbPort, TimeSpan.FromSeconds(60), cancellationToken);
            await Task.Delay(1000, cancellationToken);
            foreach (var required in new[] { "database", "laravel", "reverb", "queue", "scheduler" })
                if (!_children.TryGetValue(required, out var child) || child.Process.HasExited)
                    throw new InvalidOperationException($"The required {required} process did not remain running during startup.");
            WriteRuntimeStatus();
            onReady?.Invoke();

            while (!cancellationToken.IsCancellationRequested)
            {
                RestartExitedProcesses();
                WriteRuntimeStatus();
                await Task.Delay(TimeSpan.FromSeconds(3), cancellationToken);
            }
        }
        finally
        {
            await StopAllAsync();
            WriteRuntimeStatus(stopped: true);
            Log("runtime", "TablePlay stopped.");
        }
    }

    private InstallationConfig LoadConfiguration()
    {
        var path = Path.Combine(_root, "config", "installation.json");
        var config = JsonSerializer.Deserialize<InstallationConfig>(File.ReadAllText(path), JsonOptions)
            ?? throw new InvalidOperationException("installation.json is empty.");

        if (config.ServerPort is < 1 or > 65535 || config.ReverbPort is < 1 or > 65535 || config.DatabasePort is < 1 or > 65535)
        {
            throw new InvalidOperationException("installation.json contains an invalid port.");
        }

        return config;
    }

    private void StartDatabase()
    {
        var executable = Path.Combine(_root, "database", "bin", "mysqld.exe");
        var defaultsFile = Path.Combine(_root, "database", "my.ini");
        StartChild("database", executable, ["--defaults-file=" + defaultsFile, "--console"], Path.Combine(_root, "database"));
    }

    private void StartApplicationProcesses()
    {
        var php = Path.Combine(_root, "php", "php.exe");
        var server = Path.Combine(_root, "server");

        var laravelRouter = Path.Combine(server, "vendor", "laravel", "framework", "src", "Illuminate", "Foundation", "resources", "server.php");
        StartChild("laravel", php,
        [
            "-d", "xdebug.mode=off",
            "-d", "upload_max_filesize=256M",
            "-d", "post_max_size=260M",
            "-d", "max_input_time=600",
            "-d", "max_execution_time=600",
            "-S", $"0.0.0.0:{_config.ServerPort}",
            "-t", Path.Combine(server, "public"),
            laravelRouter,
        // Laravel's built-in router resolves index.php from getcwd(). The
        // working directory must therefore be public, matching artisan serve.
        ], Path.Combine(server, "public"));

        StartChild("reverb", php,
        [
            "-d", "xdebug.mode=off",
            "artisan", "reverb:start", "--host=0.0.0.0", $"--port={_config.ReverbPort}",
        ], server);

        StartChild("queue", php,
        [
            "-d", "xdebug.mode=off",
            "artisan", "queue:work", "--sleep=1", "--tries=3", "--timeout=90",
        ], server);

        StartChild("scheduler", php,
        [
            "-d", "xdebug.mode=off",
            "artisan", "schedule:work",
        ], server);
    }

    private void StartChild(string name, string executable, IReadOnlyList<string> arguments, string workingDirectory)
    {
        if (!File.Exists(executable))
        {
            throw new FileNotFoundException($"The {name} executable is missing.", executable);
        }

        var startInfo = new ProcessStartInfo
        {
            FileName = executable,
            WorkingDirectory = workingDirectory,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
        };

        foreach (var argument in arguments)
        {
            startInfo.ArgumentList.Add(argument);
        }

        startInfo.Environment["APP_ENV"] = "production";
        var process = new Process { StartInfo = startInfo, EnableRaisingEvents = true };
        process.OutputDataReceived += (_, eventArgs) => { if (eventArgs.Data is not null) Log(name, eventArgs.Data); };
        process.ErrorDataReceived += (_, eventArgs) => { if (eventArgs.Data is not null) Log(name, eventArgs.Data); };

        if (!process.Start())
        {
            throw new InvalidOperationException($"Windows could not start the {name} process.");
        }

        process.BeginOutputReadLine();
        process.BeginErrorReadLine();
        _children[name] = new ManagedChild(name, executable, arguments.ToArray(), workingDirectory, process);
        Log("runtime", $"Started {name} with PID {process.Id}.");
    }

    private void RestartExitedProcesses()
    {
        foreach (var child in _children.Values.ToArray())
        {
            if (!child.Process.HasExited)
            {
                continue;
            }

            Log("runtime", $"{child.Name} exited with code {child.Process.ExitCode}; restarting it.");
            if (!_restartHistory.TryGetValue(child.Name, out var history))
                _restartHistory[child.Name] = history = new Queue<DateTimeOffset>();
            var now = DateTimeOffset.UtcNow;
            while (history.Count > 0 && now - history.Peek() > TimeSpan.FromMinutes(1)) history.Dequeue();
            history.Enqueue(now);
            if (history.Count > 5)
                throw new InvalidOperationException($"The {child.Name} process exited more than five times in one minute. TablePlay stopped it to prevent an endless crash loop.");
            child.Process.Dispose();
            _children.Remove(child.Name);

            if (child.Name.Equals("database", StringComparison.OrdinalIgnoreCase))
            {
                StartDatabase();
            }
            else
            {
                StartChild(child.Name, child.Executable, child.Arguments, child.WorkingDirectory);
            }
        }
    }

    private async Task StopAllAsync()
    {
        foreach (var name in new[] { "scheduler", "queue", "reverb", "laravel" })
        {
            StopChild(name);
        }

        var database = _children.GetValueOrDefault("database");
        if (database is not null && !database.Process.HasExited)
        {
            try
            {
                var admin = Path.Combine(_root, "database", "bin", "mysqladmin.exe");
                var clientFile = Path.Combine(_root, "config", "database-client.ini");
                var stop = Process.Start(new ProcessStartInfo
                {
                    FileName = admin,
                    WorkingDirectory = Path.GetDirectoryName(admin)!,
                    UseShellExecute = false,
                    CreateNoWindow = true,
                    ArgumentList = { $"--defaults-extra-file={clientFile}", "shutdown" },
                });
                if (stop is not null)
                {
                    await stop.WaitForExitAsync().WaitAsync(TimeSpan.FromSeconds(15));
                }
            }
            catch (Exception exception)
            {
                Log("runtime", "Graceful database shutdown failed: " + exception.Message);
            }
        }

        StopChild("database");
    }

    private void StopChild(string name)
    {
        if (!_children.Remove(name, out var child))
        {
            return;
        }

        try
        {
            if (!child.Process.HasExited)
            {
                child.Process.Kill(entireProcessTree: true);
                child.Process.WaitForExit(10000);
            }
        }
        catch (Exception exception)
        {
            Log("runtime", $"Could not stop {name}: {exception.Message}");
        }
        finally
        {
            child.Process.Dispose();
        }
    }

    private void WriteRuntimeStatus(bool stopped = false)
    {
        try
        {
            var processes = _children.ToDictionary(
                item => item.Key,
                item => new
                {
                    running = !stopped && !item.Value.Process.HasExited,
                    pid = !stopped && !item.Value.Process.HasExited ? item.Value.Process.Id : (int?) null,
                });
            var status = new
            {
                updated_at = DateTimeOffset.Now,
                server_port = _config.ServerPort,
                reverb_port = _config.ReverbPort,
                database_port = _config.DatabasePort,
                processes,
            };
            File.WriteAllText(Path.Combine(_logDirectory, "runtime-status.json"), JsonSerializer.Serialize(status, JsonOptions));
        }
        catch (Exception exception)
        {
            Log("runtime", "Could not write runtime status: " + exception.Message);
        }
    }

    private void Log(string name, string message)
    {
        lock (_logLock)
        {
            Directory.CreateDirectory(_logDirectory);
            var line = $"[{DateTimeOffset.Now:yyyy-MM-dd HH:mm:ss zzz}] {message}{Environment.NewLine}";
            File.AppendAllText(Path.Combine(_logDirectory, name + ".log"), line);
        }
    }

    private async Task WaitForManagedPortAsync(string processName, int port, TimeSpan timeout, CancellationToken cancellationToken)
    {
        var deadline = DateTimeOffset.UtcNow + timeout;
        while (DateTimeOffset.UtcNow < deadline)
        {
            cancellationToken.ThrowIfCancellationRequested();
            if (!_children.TryGetValue(processName, out var child))
                throw new InvalidOperationException($"The {processName} process was not started.");
            if (child.Process.HasExited)
                throw new InvalidOperationException($"The {processName} process exited with code {child.Process.ExitCode} before port {port} became ready.");
            try
            {
                using var client = new TcpClient();
                await client.ConnectAsync("127.0.0.1", port, cancellationToken).AsTask().WaitAsync(TimeSpan.FromSeconds(2), cancellationToken);
                return;
            }
            catch when (!cancellationToken.IsCancellationRequested)
            {
                await Task.Delay(500, cancellationToken);
            }
        }

        throw new TimeoutException($"The {processName} process did not open port {port} within {timeout.TotalSeconds:0} seconds.");
    }

    public static void WriteEmergencyLog(Exception exception)
    {
        try
        {
            var root = Directory.GetParent(AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar))?.FullName
                ?? AppContext.BaseDirectory;
            var logs = Path.Combine(root, "logs");
            Directory.CreateDirectory(logs);
            File.AppendAllText(Path.Combine(logs, "service-errors.log"), $"[{DateTimeOffset.Now:O}] {exception}{Environment.NewLine}");
        }
        catch
        {
            // There is no safer fallback when the installation directory itself is unavailable.
        }
    }

    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNameCaseInsensitive = true,
        WriteIndented = true,
    };

    private sealed record ManagedChild(string Name, string Executable, string[] Arguments, string WorkingDirectory, Process Process);

    private sealed class InstallationConfig
    {
        public int ServerPort { get; set; } = 8000;
        public int ReverbPort { get; set; } = 8080;
        public int DatabasePort { get; set; } = 3310;
    }
}
