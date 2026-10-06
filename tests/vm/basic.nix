# https://wiki.nixos.org/wiki/NixOS_VM_tests
{
  pkgs26_05,
  # Nextcloud major versions to test, e.g. [ "34" ] for a quick single-version run
  versions ? [
    "32"
    "33"
    "34"
  ],
  ...
}:

let
  inherit (pkgs26_05) lib;
  # Safe lookup on pkgs26_05 catching eval errors
  tryAttr2605 =
    name:
    if builtins.hasAttr name pkgs26_05 then
      (
        let
          t = builtins.tryEval (builtins.getAttr name pkgs26_05);
        in
        if t.success then t.value else null
      )
    else
      null;

  packages = lib.genAttrs versions (version: tryAttr2605 "nextcloud${version}");

  src = ../../.;

  # Frontend bundle (js/), built from the git-tracked sources
  # Update npmDepsHash after changes of package-lock.json with:
  # nix run github:NixOS/nixpkgs/nixos-26.05#prefetch-npm-deps -- package-lock.json
  qownnotesFrontend = pkgs26_05.buildNpmPackage {
    pname = "qownnotes-frontend";
    version = "0.0.0";
    inherit src;
    npmDepsHash = "sha256-TJhk/2DoUZM4zy4yy4Urv65XDh4x2YVcD+Rhw4eIXMg=";
    installPhase = ''
      runHook preInstall
      mkdir -p $out
      cp -r js $out/
      runHook postInstall
    '';
  };

  # The app as it is released: PHP sources, templates and the built frontend
  qownnotesApp = pkgs26_05.runCommand "qownnotes-app" { inherit src; } ''
    mkdir -p $out
    cp -r $src/* $out/
    chmod -R u+w $out
    rm -rf $out/tests $out/docs $out/docker $out/src $out/vendor $out/node_modules $out/js
    cp -r ${qownnotesFrontend}/js $out/js
  '';

  mkNode = version: pkg: {
    "nextcloud${version}" = _: {
      virtualisation.memorySize = 1536;
      services.nextcloud = {
        enable = true;
        package = pkg;
        hostName = "localhost";
        config = {
          adminuser = "admin";
          adminpassFile = "/etc/nextcloud-adminpass";
          dbtype = "sqlite";
          dbname = "nextcloud";
        };
        extraApps = {
          qownnotes = qownnotesApp;
        };
        extraAppsEnable = true;
      };
      environment.etc."nextcloud-adminpass".text = "adminpass";
      # For verifying notes.sqlite files written by the app
      environment.systemPackages = [ pkgs26_05.sqlite ];
    };
  };

  nodes = lib.foldl' (acc: version: acc // mkNode version packages.${version}) { } versions;

  testCalls = lib.concatMapStringsSep "\n" (
    version: ''test_version(nextcloud${version}, "${version}", "${packages.${version}.version}")''
  ) versions;
in
# Fail early if any required Nextcloud package is missing
assert lib.all (
  version: lib.assertMsg (packages.${version} != null) "Missing required package: nextcloud${version}"
) versions;

pkgs26_05.testers.nixosTest {
  name = "nextcloud_qownnotes";
  inherit nodes;
  interactive.sshBackdoor.enable = true; # provides ssh-config & vsock access (needs host vsock support)
  testScript = builtins.readFile ./test_qownnotes.py + ''

    ${testCalls}
    print("ALL_TESTS_DONE")
  '';
}
