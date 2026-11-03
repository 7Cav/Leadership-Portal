---
title: Link game identities
summary: Link each trooper's player ID so the game server can find them.
section: tutorial
order: 5
audience: leader
page: /game-identities
try: Open Game identities
minutes: 5
---

The game server knows players by their player ID. Until a trooper is linked, their practice attendance and make-ups are not recorded for you.

## Link a trooper

1. Choose **Game identities** in the sidebar. **Needs a link** shows the troopers with no link yet.
2. Choose a trooper, then enter the game's ID for them. The page says which ID the game uses.
3. Choose **Save identity**.

Each trooper's row shows whether they are linked on the game their unit plays, such as "HLLV linked" or "HLLV not linked", and the other games they are linked on. **Main game not linked** lists only the troopers still missing that game. Choose a trooper's name to see their identities, then **Edit** or **Remove** one. **Add identity** is only for adding a new one.

## Import many identities at once

1. On **Game identities**, choose **Import identities**.
2. Choose a .txt or .csv file, or paste the lines. Each line is one identity: trooper, game, ID kind and ID, separated by commas or tabs. For example `Novak.E, HLL, Steam ID, 76561198012345678`. You can leave the ID kind out to use the game's first kind. A fifth column, the gamertag, is optional. **Download example** gives you a file to start from.
3. Choose **Preview**. Each line says whether it will add or change an identity, or why it is skipped or refused.
4. To change identities a trooper already has, tick **Replace existing identities**. Without it, those lines are skipped.
5. Choose the import button, such as **Import 3 identities**, to save every line that adds or changes an identity. If anything changed since the preview, nothing is saved and you preview again.

> [!good-to-know] For a 32-character Xbox, PlayStation or Epic ID on a Hell Let Loose game, write `?` as the ID kind (or leave it empty) and the preview asks the game server which kind it is and fills in the gamertag. A player the server has not seen is refused until you give the kind.

> [!good-to-know] The trooper is matched on their exact Cav name. Atkins.D and Atkins.DS are different people.

> [!good-to-know] An Admin can also import IDs for people who have left. A trooper who is not serving gets the identity as usual, ready for when they rejoin. A name with no trooper in the portal is kept on file and listed under **Kept identities**; when someone with exactly that name joins, the next roster sync gives them the identity. Other leaders see these lines refused.

> [!good-to-know] The file can hold up to 500 lines. Steam IDs are checked with Steam where possible; a line marked "Not checked with Steam" is still saved.

## Check the warnings are gone

The warning at the top of **Home**, the roster and your roll call goes away once every trooper is linked.

> [!good-to-know] After a captured practice, players the server saw who are linked to no trooper are listed on the roll call. You can link them from there too.
