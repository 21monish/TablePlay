add_executable(tableplay_updater WIN32
  "main.cpp"
)

apply_standard_settings(tableplay_updater)
target_compile_features(tableplay_updater PRIVATE cxx_std_17)
target_compile_definitions(tableplay_updater PRIVATE "UNICODE" "_UNICODE")
target_link_libraries(tableplay_updater PRIVATE shell32)

install(TARGETS tableplay_updater
  RUNTIME DESTINATION "${CMAKE_INSTALL_PREFIX}"
  COMPONENT Runtime)
