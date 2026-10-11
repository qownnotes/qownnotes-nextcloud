{
  pkgs,
  ...
}:

{
  # https://devenv.sh/packages/
  packages = with pkgs; [
    python3
    zellij
  ];

  enterShell = ''
    echo "🛠️ QOwnNotes Nextcloud app dev shell"
  '';

  # https://devenv.sh/git-hooks/
  git-hooks = {
    excludes = [
      "appinfo/signature.json"
      "^js/"
      "^l10n/"
    ];
  };

  # See full reference at https://devenv.sh/reference/options/
}
