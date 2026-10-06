{
  description = "Nextcloud QOwnNotes app NixOS VM tests (Nextcloud 32-34)";

  inputs = {
    # NixOS 26.05 provides all supported stable Nextcloud versions
    nixpkgs26_05.url = "github:NixOS/nixpkgs/nixos-26.05";
  };

  outputs =
    {
      nixpkgs26_05,
      ...
    }:
    let
      system = "x86_64-linux";
      pkgs26_05 = import nixpkgs26_05 { inherit system; };
      mkTest = versions: import ./tests/vm/basic.nix { inherit pkgs26_05 versions; };
    in
    {
      nixosTests = {
        nextcloud-qownnotes = mkTest [
          "32"
          "33"
          "34"
        ];
        nextcloud-qownnotes-32 = mkTest [ "32" ];
        nextcloud-qownnotes-33 = mkTest [ "33" ];
        nextcloud-qownnotes-34 = mkTest [ "34" ];
      };
    };
}
