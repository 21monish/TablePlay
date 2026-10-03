#include <windows.h>
#include <shellapi.h>

#include <chrono>
#include <filesystem>
#include <fstream>
#include <map>
#include <stdexcept>
#include <string>
#include <vector>

namespace fs = std::filesystem;

std::map<std::wstring, std::wstring> ParseArguments() {
  int count = 0;
  LPWSTR* values = CommandLineToArgvW(GetCommandLineW(), &count);
  std::map<std::wstring, std::wstring> arguments;
  if (values != nullptr) {
    for (int index = 1; index + 1 < count; index += 2) {
      arguments[values[index]] = values[index + 1];
    }
    LocalFree(values);
  }
  return arguments;
}

std::wstring PowerShellLiteral(const std::wstring& value) {
  std::wstring escaped;
  for (const wchar_t character : value) {
    escaped += character == L'\'' ? L"''" : std::wstring(1, character);
  }
  return escaped;
}

void CopyTree(const fs::path& source, const fs::path& destination) {
  fs::create_directories(destination);
  for (const auto& entry : fs::recursive_directory_iterator(source)) {
    const fs::path relative = fs::relative(entry.path(), source);
    const fs::path target = destination / relative;
    if (entry.is_directory()) {
      fs::create_directories(target);
    } else if (entry.is_regular_file()) {
      fs::create_directories(target.parent_path());
      fs::copy_file(entry.path(), target, fs::copy_options::overwrite_existing);
    }
  }
}

DWORD RunPowerShellExpand(const fs::path& package, const fs::path& staging) {
  wchar_t windows_directory[MAX_PATH];
  GetWindowsDirectoryW(windows_directory, MAX_PATH);
  const fs::path powershell = fs::path(windows_directory) /
      L"System32/WindowsPowerShell/v1.0/powershell.exe";
  std::wstring command = L"\"" + powershell.wstring() +
      L"\" -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command "
      L"\"Expand-Archive -LiteralPath '" +
      PowerShellLiteral(package.wstring()) + L"' -DestinationPath '" +
      PowerShellLiteral(staging.wstring()) + L"' -Force\"";
  std::vector<wchar_t> mutable_command(command.begin(), command.end());
  mutable_command.push_back(L'\0');

  STARTUPINFOW startup = {};
  startup.cb = sizeof(startup);
  PROCESS_INFORMATION process = {};
  if (!CreateProcessW(
          powershell.c_str(), mutable_command.data(), nullptr, nullptr, FALSE,
          CREATE_NO_WINDOW, nullptr, nullptr, &startup, &process)) {
    throw std::runtime_error("Windows could not start the package extractor.");
  }

  WaitForSingleObject(process.hProcess, INFINITE);
  DWORD exit_code = 1;
  GetExitCodeProcess(process.hProcess, &exit_code);
  CloseHandle(process.hThread);
  CloseHandle(process.hProcess);
  return exit_code;
}

int APIENTRY wWinMain(HINSTANCE, HINSTANCE, wchar_t*, int) {
  fs::path backup;
  fs::path staging;
  fs::path target;
  std::wstring app_name = L"tableplay_staff.exe";
  try {
    const auto arguments = ParseArguments();
    const fs::path package = arguments.at(L"--package");
    target = arguments.at(L"--target");
    app_name = arguments.at(L"--app");
    const DWORD process_id = std::stoul(arguments.at(L"--pid"));

    if (_wcsicmp(app_name.c_str(), L"tableplay_staff.exe") != 0 ||
        package.extension() != L".zip" || !fs::is_regular_file(package) ||
        !fs::is_directory(target)) {
      throw std::runtime_error("The update request did not pass validation.");
    }

    if (HANDLE app_process = OpenProcess(SYNCHRONIZE, FALSE, process_id)) {
      WaitForSingleObject(app_process, INFINITE);
      CloseHandle(app_process);
    } else {
      Sleep(700);
    }

    wchar_t temporary_directory[MAX_PATH];
    GetTempPathW(MAX_PATH, temporary_directory);
    const auto stamp = std::chrono::steady_clock::now()
                           .time_since_epoch()
                           .count();
    staging = fs::path(temporary_directory) /
        (L"TablePlayUpdate-" + std::to_wstring(stamp));
    backup = fs::path(temporary_directory) /
        (L"TablePlayBackup-" + std::to_wstring(stamp));
    fs::create_directories(staging);

    if (RunPowerShellExpand(package, staging) != 0 ||
        !fs::is_regular_file(staging / app_name) ||
        !fs::is_regular_file(staging / L"flutter_windows.dll") ||
        !fs::is_directory(staging / L"data")) {
      throw std::runtime_error(
          "The downloaded archive is not a complete TablePlay Staff release.");
    }

    CopyTree(target, backup);
    try {
      CopyTree(staging, target);
    } catch (...) {
      CopyTree(backup, target);
      throw;
    }

    const fs::path application = target / app_name;
    const HINSTANCE launch = ShellExecuteW(
        nullptr, L"open", application.c_str(), nullptr, target.c_str(),
        SW_SHOWNORMAL);
    if (reinterpret_cast<INT_PTR>(launch) <= 32) {
      CopyTree(backup, target);
      throw std::runtime_error("TablePlay could not restart after the update.");
    }

    fs::remove_all(staging);
    fs::remove_all(backup);
    std::error_code ignored;
    fs::remove(package, ignored);
    return EXIT_SUCCESS;
  } catch (const std::exception& error) {
    if (!backup.empty() && fs::exists(backup) && !target.empty()) {
      try {
        CopyTree(backup, target);
      } catch (...) {
      }
    }

    if (!target.empty() && fs::is_directory(target)) {
      try {
        std::ofstream marker(target / L".tableplay_update_error", std::ios::trunc);
        marker << error.what();
        marker.close();
        const fs::path application = target / app_name;
        if (fs::is_regular_file(application)) {
          ShellExecuteW(
              nullptr, L"open", application.c_str(), nullptr, target.c_str(),
              SW_SHOWNORMAL);
        }
      } catch (...) {
      }
    }
    return EXIT_FAILURE;
  }
}
