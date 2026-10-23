using System.Net;
using System.Net.NetworkInformation;
using System.Net.Sockets;

namespace TablePlay.Runtime;

internal static class LocalPorts
{
    internal const int PreferredHttp = 18740;
    internal const int PreferredRealtime = 18741;

    internal static int FindAvailable(int preferred, params int[] excluded)
    {
        for (var port = preferred; port < Math.Min(preferred + 100, 65536); port++)
            if (!excluded.Contains(port) && CanBind(port)) return port;
        throw new InvalidOperationException($"No available TCP port was found in {preferred}-{Math.Min(preferred + 99, 65535)}. Ask your administrator to check Windows reserved ports. No other application was stopped.");
    }

    internal static bool CanBind(int port)
    {
        if (port is < 1024 or > 65535) return false;
        if (IPGlobalProperties.GetIPGlobalProperties().GetActiveTcpListeners().Any(endpoint => endpoint.Port == port)) return false;
        Socket? ipv4 = null;
        Socket? ipv6 = null;
        try
        {
            // Binding checks Windows exclusions/permissions too, not just listeners.
            ipv4 = new Socket(AddressFamily.InterNetwork, SocketType.Stream, ProtocolType.Tcp) { ExclusiveAddressUse = true };
            ipv4.Bind(new IPEndPoint(IPAddress.Any, port));
            if (Socket.OSSupportsIPv6)
            {
                ipv6 = new Socket(AddressFamily.InterNetworkV6, SocketType.Stream, ProtocolType.Tcp) { ExclusiveAddressUse = true, DualMode = false };
                ipv6.Bind(new IPEndPoint(IPAddress.IPv6Any, port));
            }
            return true;
        }
        catch (SocketException) { return false; }
        finally { ipv6?.Dispose(); ipv4?.Dispose(); }
    }

    internal static void RequireAvailable(params (int Port, string Name)[] ports)
    {
        if (ports.Select(value => value.Port).Distinct().Count() != ports.Length)
            throw new InvalidOperationException("TablePlay HTTP, realtime and database ports must be different. Check config/installation.json.");
        foreach (var (port, name) in ports)
            if (!CanBind(port)) throw new InvalidOperationException($"TablePlay {name} port {port} is occupied, reserved or unavailable. No application was stopped and the saved port was not changed. Check Server Manager and config/installation.json; an administrator must coordinate any port change and device reconnection.");
    }
}
