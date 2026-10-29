// A small greeting for anyone who opens the browser console: the crossed flags of Belgium and the Netherlands, as on
// a friendship pin, drawn in characters and coloured with the console's %c styles. ART is the drawing and PAINT gives
// each character's colour by a PALETTE key, cell for cell; a space in either is left blank.

export const PALETTE = {
    k: '#1C1C1C',
    y: '#F9D616',
    r: '#CE2029',
    w: '#F2F2F2',
    b: '#2E4AA0',
    g: '#C9A23A',
    d: '#7A5A1E',
};

export const ART = [
    '                     %%##     #%%#',
    '                  *##%@#      *@%#####',
    '              *#%%@@@@@%*     %%%%%%%####',
    '           **+*%@@@@@@@@#     @%%%%%%%%%%###',
    '         **++++%@@@@@@@@%    #%%##%%%%%%%%%#%##',
    '     ###+++++++*%@@@@@@@%####%*=++*#%#%%%%%%%%%%%###',
    '######%#*+++++++%@@@@@@@%##%#%=======+*###%%%%%%%%%#%%%',
    '%%%%%%%##+++++++#@@@@@@@@%*%%#==========+*##%%%%%%%%%%%',
    '%%%%%%%#%*+++++++%@@@@@@@%*#%*=============+*###%%%%%%%',
    '%%%%%%%%##+++++++#@@@@@@@@%#%####*++===========+**###%#',
    ' %%%%%%%#%+++++++*%@@@@@@@%%%%%%%%####+==============#',
    '  %%%%%%%%*+++++++%@@@@@@@%%%%%%%%%%%%##*+==========*#',
    '  %%%%%%%#*+++++++*@@@@@@@%%%##%%%%%%%%%%##*+=======%',
    '  #%%%%%%##++++++++#@%%#######*####%%%%%%%%%##*+====#',
    '  #%%%%%%%#+++++++**     ##*%#+     *##%%%%%%%%######',
    '  #%%%%%%%#*+++*         %**#%+        *#%%%%%%%%%%%*',
    '   #%%%%%%%***          #%*#*%*          **##%%%%%%#',
    '   #%%%####             #***+**             +**####*',
    '    **                     +++',
];

export const PAINT = [
    '                     dkdd     dkkd',
    '                  gdddkd      gkrrrddd',
    '              gddkkkkkkdg     ddrrrrrrrdd',
    '           ggygkkkkkkkkkd     krrrrrrrrrrrdd',
    '         ggyyyydkkkkkkkkd    dkrrrrrrrrrrrrrrdd',
    '     dddyyyyyyygkkkkkkkkkddddkgwwwgdrrrrrrrrrrrrdddd',
    'drrrrrrdgyyyyyyydkkkkkkkkddkdkwwwwwwwwgdrrrrrrrrrrrrrrd',
    'drrrrrrddyyyyyyydkkkkkkkkkgkddwwwwwwwwwwwgdrrrrrrrrrrrd',
    'drrrrrrrdgyyyyyyykkkkkkkkkgdkgwwwwwwwwwwwwwwgdrrrrrrrrd',
    'drrrrrrrddyyyyyyydkkkkkkkkddkbbbbbgwwwwwwwwwwwwwggrrrrd',
    ' rrrrrrrrdyyyyyyygkkkkkkkkddbbbbbbbbbbgwwwwwwwwwwwwwwd',
    '  rrrrrrrdgyyyyyyydkkkkkkkkdbbbbbbbbbbbbgwwwwwwwwwwwgd',
    '  drrrrrrdgyyyyyyygkkkkkkkkdbbbbbbbbbbbbbbbgwwwwwwwwd',
    '  drrrrrrrdyyyyyyyydkdddddddddgddbbbbbbbbbbbbbgwwwwwd',
    '  drrrrrrrdyyyyyyggg     ddgddw     gbbbbbbbbbbbbbbbd',
    '  drrrrrrrrgyyyg         dggddw        gbbbbbbbbbbbbg',
    '   drrrrrrrggg          ddgdgdg          gbbbbbbbbbd',
    '   drrrrrrg             dgggggg             ggbbbbdg',
    '    gg                     wwg',
];

const BASE = 'font-family: monospace; line-height: 1;';

// The drawing as one console.log call: a format string with a %c before each run of one colour, and the styles.
export function flagMessage() {
    let format = '';
    const styles = [];

    ART.forEach((line, r) => {
        let current;
        [...line].forEach((char, c) => {
            const key = char === ' ' ? null : PAINT[r][c];
            if (key !== current) {
                format += '%c';
                styles.push(key ? `color: ${PALETTE[key]}; ${BASE}` : BASE);
                current = key;
            }
            format += char === '%' ? '%%' : char;
        });
        format += '\n';
    });

    return [format.trimEnd(), ...styles];
}

export function printConsoleFlags(target = console) {
    target.log(...flagMessage());
}
