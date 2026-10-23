using System.Net;
using System.Net.Sockets;
using TablePlay.Runtime;

if (LocalPorts.CanBind(80) || LocalPorts.CanBind(0) || LocalPorts.CanBind(65536)) throw new Exception("Unsafe port range accepted.");
var candidate = LocalPorts.FindAvailable(18740);
using var occupied = new TcpListener(IPAddress.Any, candidate);
occupied.Start();
if (LocalPorts.CanBind(candidate)) throw new Exception("An occupied port was accepted.");
var next = LocalPorts.FindAvailable(candidate);
if (next == candidate) throw new Exception("Selection failed to skip an occupied port.");
if (LocalPorts.FindAvailable(next, next) == next) throw new Exception("Excluded port was selected twice.");
try { LocalPorts.RequireAvailable((candidate, "HTTP")); throw new Exception("Conflict was ignored."); }
catch (InvalidOperationException e) when (e.Message.Contains("saved port was not changed")) { }
try { LocalPorts.RequireAvailable((next, "HTTP"), (next, "realtime")); throw new Exception("Duplicate ports accepted."); }
catch (InvalidOperationException e) when (e.Message.Contains("must be different")) { }
occupied.Stop();
LocalPorts.RequireAvailable((next, "HTTP"));
Console.WriteLine("PASS: occupied/excluded ports skipped, duplicate and unsafe ports rejected, saved-port conflicts fail safely. No services or configuration changed.");
