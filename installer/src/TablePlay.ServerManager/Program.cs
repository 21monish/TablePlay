namespace TablePlay.ServerManager;

internal static class Program
{
    [STAThread]
    private static void Main(string[] args)
    {
        ApplicationConfiguration.Initialize();
        Application.Run(new ManagerForm(args.Any(argument => argument.Equals("--uninstall", StringComparison.OrdinalIgnoreCase))));
    }
}
