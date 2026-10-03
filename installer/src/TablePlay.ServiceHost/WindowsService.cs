using System.Runtime.InteropServices;

namespace TablePlay.ServiceHost;

internal static class WindowsService
{
    private const string ServiceName = "TablePlayServer";
    private const uint ServiceWin32OwnProcess = 0x00000010;
    private const uint ServiceStartPending = 0x00000002;
    private const uint ServiceStopPending = 0x00000003;
    private const uint ServiceRunning = 0x00000004;
    private const uint ServiceStopped = 0x00000001;
    private const uint ServiceAcceptStop = 0x00000001;
    private const uint ServiceAcceptShutdown = 0x00000004;
    private const uint ServiceAcceptPreShutdown = 0x00000100;
    private const uint ServiceControlStop = 0x00000001;
    private const uint ServiceControlShutdown = 0x00000005;
    private const uint ServiceControlPreShutdown = 0x0000000F;

    private static readonly ServiceMainCallback ServiceMainDelegate = ServiceMain;
    private static readonly HandlerExCallback HandlerDelegate = Handler;
    private static readonly CancellationTokenSource Cancellation = new();
    private static IntPtr _statusHandle;
    private static uint _checkpoint = 1;

    public static int Run()
    {
        var table = new[]
        {
            new ServiceTableEntry { ServiceName = ServiceName, ServiceMain = ServiceMainDelegate },
            new ServiceTableEntry { ServiceName = null, ServiceMain = null },
        };

        if (StartServiceCtrlDispatcher(table))
        {
            return 0;
        }

        var error = Marshal.GetLastWin32Error();
        RuntimeSupervisor.WriteEmergencyLog(new InvalidOperationException($"StartServiceCtrlDispatcher failed with Windows error {error}."));
        return error;
    }

    private static void ServiceMain(int argumentCount, IntPtr arguments)
    {
        _statusHandle = RegisterServiceCtrlHandlerEx(ServiceName, HandlerDelegate, IntPtr.Zero);
        if (_statusHandle == IntPtr.Zero)
        {
            RuntimeSupervisor.WriteEmergencyLog(new InvalidOperationException($"RegisterServiceCtrlHandlerEx failed with Windows error {Marshal.GetLastWin32Error()}."));
            return;
        }

        SetState(ServiceStartPending, waitHint: 120000);

        try
        {
            var ready = 0;
            using var pendingTimer = new Timer(
                _ => { if (Volatile.Read(ref ready) == 0) SetState(ServiceStartPending, waitHint: 120000); },
                null,
                TimeSpan.FromSeconds(5),
                TimeSpan.FromSeconds(5));
            new RuntimeSupervisor().RunAsync(Cancellation.Token, () =>
            {
                Interlocked.Exchange(ref ready, 1);
                pendingTimer.Change(Timeout.Infinite, Timeout.Infinite);
                SetState(ServiceRunning);
            }).GetAwaiter().GetResult();
            SetState(ServiceStopped);
        }
        catch (OperationCanceledException)
        {
            SetState(ServiceStopped);
        }
        catch (Exception exception)
        {
            RuntimeSupervisor.WriteEmergencyLog(exception);
            SetState(ServiceStopped, win32ExitCode: 1);
        }
    }

    private static uint Handler(uint control, uint eventType, IntPtr eventData, IntPtr context)
    {
        if (control is ServiceControlStop or ServiceControlShutdown or ServiceControlPreShutdown)
        {
            SetState(ServiceStopPending, waitHint: 30000);
            Cancellation.Cancel();
        }

        return 0;
    }

    private static void SetState(uint state, uint waitHint = 0, uint win32ExitCode = 0)
    {
        if (_statusHandle == IntPtr.Zero)
        {
            return;
        }

        var pending = state is ServiceStartPending or ServiceStopPending;
        var status = new ServiceStatus
        {
            ServiceType = ServiceWin32OwnProcess,
            CurrentState = state,
            ControlsAccepted = state == ServiceRunning
                ? ServiceAcceptStop | ServiceAcceptShutdown | ServiceAcceptPreShutdown
                : 0,
            Win32ExitCode = win32ExitCode,
            ServiceSpecificExitCode = 0,
            CheckPoint = pending ? _checkpoint++ : 0,
            WaitHint = waitHint,
        };

        SetServiceStatus(_statusHandle, ref status);
    }

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct ServiceTableEntry
    {
        [MarshalAs(UnmanagedType.LPWStr)] public string? ServiceName;
        public ServiceMainCallback? ServiceMain;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct ServiceStatus
    {
        public uint ServiceType;
        public uint CurrentState;
        public uint ControlsAccepted;
        public uint Win32ExitCode;
        public uint ServiceSpecificExitCode;
        public uint CheckPoint;
        public uint WaitHint;
    }

    [UnmanagedFunctionPointer(CallingConvention.Winapi)]
    private delegate void ServiceMainCallback(int argumentCount, IntPtr arguments);

    [UnmanagedFunctionPointer(CallingConvention.Winapi)]
    private delegate uint HandlerExCallback(uint control, uint eventType, IntPtr eventData, IntPtr context);

    [DllImport("advapi32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool StartServiceCtrlDispatcher([In] ServiceTableEntry[] serviceTable);

    [DllImport("advapi32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    private static extern IntPtr RegisterServiceCtrlHandlerEx(string serviceName, HandlerExCallback callback, IntPtr context);

    [DllImport("advapi32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool SetServiceStatus(IntPtr serviceStatusHandle, ref ServiceStatus serviceStatus);
}
