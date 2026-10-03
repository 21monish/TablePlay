namespace TablePlay.ServiceHost;

internal static class Program
{
    public static int Main(string[] args)
    {
        var consoleMode = args.Any(argument => argument.Equals("--console", StringComparison.OrdinalIgnoreCase));

        if (!consoleMode && !Environment.UserInteractive)
        {
            return WindowsService.Run();
        }

        using var cancellation = new CancellationTokenSource();
        Console.CancelKeyPress += (_, eventArgs) =>
        {
            eventArgs.Cancel = true;
            cancellation.Cancel();
        };

        try
        {
            Console.WriteLine("TablePlay runtime is starting. Press Ctrl+C to stop it.");
            new RuntimeSupervisor().RunAsync(cancellation.Token).GetAwaiter().GetResult();
            return 0;
        }
        catch (OperationCanceledException)
        {
            return 0;
        }
        catch (Exception exception)
        {
            RuntimeSupervisor.WriteEmergencyLog(exception);
            Console.Error.WriteLine(exception);
            return 1;
        }
    }
}
