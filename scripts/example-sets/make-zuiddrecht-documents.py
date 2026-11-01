#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
# SPDX-FileCopyrightText: 2026 Conduction B.V.
"""Write the demo documents of the Zuiddrecht example set as small PDF files.

    python3 scripts/example-sets/make-zuiddrecht-documents.py

Each publication of lib/Settings/example-sets/zuiddrecht.json gets its documents
in lib/Settings/example-sets/zuiddrecht/<publication key>/. Every page says it is
demo content: Zuiddrecht is a fictitious municipality, and the only names in the
text are those of the example set's cast. The PDFs are plain text in Helvetica,
written by hand so the script needs nothing beyond Python itself. Run it again
after changing a text; the output is the same for the same input, so a re-run
without changes leaves git clean.

The withheld documents of the Woo decision (inventory numbers 4 and 5) have no
file on purpose: only the record that they were withheld is published.
"""
import json
import os
import sys

ROOT = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
FRAGMENT = os.path.join(ROOT, 'lib', 'Settings', 'example-sets', 'zuiddrecht.json')
OUT = os.path.join(ROOT, 'lib', 'Settings', 'example-sets', 'zuiddrecht')

BANNER = 'VOORBEELDDOCUMENT - demo-inhoud. Gemeente Zuiddrecht bestaat niet.'

# publication key -> list of (file name, title, paragraphs)
DOCUMENTS = {
    'pub-inspectie-verlichting': [
        ('01-inspectierapport-openbare-verlichting-lindelaan.pdf', 'Inspectierapport openbare verlichting wijk Lindelaan 2026', [
            'Opdrachtgever: gemeente Zuiddrecht, team Openbare ruimte.',
            'In de wijk Lindelaan staan 318 lantaarnpalen. Wij hebben ze in april 2026 allemaal bekeken.',
            '296 lantaarnpalen werken goed. 22 lantaarnpalen hebben een nieuwe lamp of een nieuwe kast nodig.',
            'Het fietspad langs de Lindelaan heeft geen verlichting. Over 1,4 kilometer is het daar na zonsondergang donker.',
            'Advies: zet verlichting langs het fietspad en vervang de 22 lantaarnpalen in 2027.',
        ]),
    ],
    'pub-advies-wijkraad': [
        ('01-advies-wijkraad-verlichting-fietspad.pdf', 'Advies van de wijkraad Lindelaan-Kerkplein', [
            'Aan: het college van burgemeester en wethouders van Zuiddrecht.',
            'Onderwerp: verlichting van het fietspad langs de Lindelaan.',
            'De wijkraad vroeg in juni bewoners wat zij van het fietspad vinden. 64 bewoners deden mee.',
            'De meeste bewoners fietsen er in het donker niet graag. Ouders laten hun kinderen er in de winter niet alleen fietsen.',
            'De wijkraad adviseert zuinige verlichting langs het hele pad, die dimt als er niemand fietst.',
        ]),
    ],
    'pub-college-fietspad': [
        ('01-besluitenlijst-college.pdf', 'Besluitenlijst van het college van burgemeester en wethouders', [
            'Vergadering van het college van Zuiddrecht.',
            'Punt 4. Verlichting fietspad Lindelaan.',
            'Besluit: het college stelt de gemeenteraad voor een krediet van 186.000 euro beschikbaar te stellen voor verlichting langs het fietspad Lindelaan.',
            'Het college volgt daarmee het advies van de wijkraad en het inspectierapport van april 2026.',
        ]),
        ('02-collegevoorstel-verlichting-fietspad.pdf', 'Collegevoorstel verlichting fietspad Lindelaan', [
            'Het fietspad langs de Lindelaan is 1,4 kilometer lang en heeft geen verlichting.',
            'Voorstel: 42 lantaarnpalen met zuinige ledlampen die dimmen als er niemand fietst.',
            'Kosten: 186.000 euro, waarvan 164.000 euro voor levering en plaatsing en 22.000 euro voor aansluiting en onvoorzien.',
            'Planning: aanbesteding in het najaar, werk in maart, klaar in zes weken.',
        ]),
    ],
    'pub-raad-fietspad': [
        ('01-raadsvoorstel-krediet-fietspad.pdf', 'Raadsvoorstel krediet verlichting fietspad Lindelaan', [
            'Aan de gemeenteraad van Zuiddrecht.',
            'Het college vraagt de raad een krediet van 186.000 euro voor verlichting langs het fietspad Lindelaan.',
            'Het fietspad is in het donker slecht te zien. Bewoners en de wijkraad vroegen om verlichting.',
        ]),
        ('02-raadsbesluit-krediet-fietspad.pdf', 'Raadsbesluit krediet verlichting fietspad Lindelaan', [
            'De gemeenteraad van Zuiddrecht besluit:',
            '1. een krediet van 186.000 euro beschikbaar te stellen voor verlichting langs het fietspad Lindelaan;',
            '2. de begroting 2026 daarop aan te passen.',
            'Aangenomen met 19 stemmen voor en 2 tegen.',
        ]),
    ],
    'pub-raadsbrief-fietspad': [
        ('01-raadsinformatiebrief-planning-fietspad.pdf', 'Raadsinformatiebrief: planning aanleg en verlichting fietspad Lindelaan', [
            'Aan de gemeenteraad van Zuiddrecht.',
            'Met deze brief vertellen wij u hoe het werk aan het fietspad Lindelaan verloopt.',
            'De opdracht voor de lantaarnpalen is gegund aan Installatiebedrijf Van Dam.',
            'Het werk begint in maart en duurt ongeveer zes weken. Het fietspad blijft open; soms fietst u een klein stuk om.',
            'Wij informeren de bewoners van de Lindelaan met een brief en op de website.',
        ]),
    ],
    'pub-overeenkomst-vandam': [
        ('01-gunningsbrief-installatiebedrijf-van-dam.pdf', 'Gunningsbrief verlichting fietspad Lindelaan', [
            'Aan: Installatiebedrijf Van Dam, Industrieweg 14, Zuiddrecht. T.a.v. mevrouw K. Smit.',
            'Wij gunnen u de opdracht voor de levering en plaatsing van 42 lantaarnpalen langs het fietspad Lindelaan.',
            'Uw inschrijving was de economisch meest voordelige. De opschortende termijn van twintig dagen is verstreken zonder bezwaar.',
        ]),
        ('02-overeenkomst-levering-en-plaatsing.pdf', 'Overeenkomst levering en plaatsing verlichting fietspad Lindelaan', [
            'Partijen: de gemeente Zuiddrecht en Installatiebedrijf Van Dam, vertegenwoordigd door Karin Smit.',
            'Opdracht: levering en plaatsing van 42 lantaarnpalen met ledverlichting en dimfunctie langs het fietspad Lindelaan.',
            'Prijs: 158.400 euro exclusief btw. Uitvoering in maart, oplevering uiterlijk eind april.',
            'Garantie: tien jaar op de armaturen, twee jaar op de plaatsing.',
        ]),
    ],
    'pub-woo-zaaksysteem': [
        ('01-woo-besluit-aanbesteding-zaaksysteem.pdf', 'Besluit op uw Woo-verzoek over de aanbesteding van het zaaksysteem', [
            'Aan: CloudGemeente B.V., t.a.v. de heer M. Visser.',
            'U vroeg ons de documenten over de aanbesteding van het nieuwe zaaksysteem. Wij vonden vijf documenten.',
            'Openbaar: de aanbestedingsleidraad (1) en het gunningsadvies (3).',
            'Deels openbaar: het proces-verbaal van de opening (2). De prijzen van de andere inschrijvers zijn gelakt (artikel 5.1, lid 1, onder c Woo).',
            'Niet openbaar: de inschrijving van een andere partij (4), artikel 5.1, lid 1, onder c Woo, en een interne e-mail (5), artikel 5.2, lid 1 en artikel 5.1, lid 2, onder e Woo.',
            'Bent u het niet eens met dit besluit? Dan kunt u binnen zes weken bezwaar maken bij het college.',
        ]),
        ('02-inventarislijst.pdf', 'Inventarislijst Woo-verzoek aanbesteding zaaksysteem', [
            '1. Aanbestedingsleidraad zaaksysteem - openbaar.',
            '2. Proces-verbaal van opening van de inschrijvingen - deels openbaar - 5.1.1.c.',
            '3. Gunningsadvies aan het college - openbaar.',
            '4. Inschrijving van een andere partij - niet openbaar - 5.1.1.c.',
            '5. Interne e-mail over de beoordeling - niet openbaar - 5.2.1, 5.1.2.e.',
        ]),
        ('03-aanbestedingsleidraad-zaaksysteem.pdf', 'Aanbestedingsleidraad zaaksysteem gemeente Zuiddrecht', [
            'De gemeente Zuiddrecht zoekt een zaaksysteem voor het behandelen van aanvragen, meldingen en Woo-verzoeken.',
            'Procedure: openbare Europese aanbesteding. Gunning op de beste prijs-kwaliteitverhouding: kwaliteit 70 procent, prijs 30 procent.',
            'Eisen: open standaarden, gegevens in Nederland of de EU, toegankelijk volgens WCAG 2.2 AA.',
        ]),
        ('04-proces-verbaal-opening-inschrijvingen.pdf', 'Proces-verbaal van opening van de inschrijvingen', [
            'Op de sluitingsdatum zijn drie inschrijvingen ontvangen.',
            'Inschrijver A: CloudGemeente B.V. Prijs: [gelakt, 5.1.1.c].',
            'Inschrijver B: [gelakt, 5.1.1.c]. Prijs: [gelakt, 5.1.1.c].',
            'Inschrijver C: [gelakt, 5.1.1.c]. Prijs: [gelakt, 5.1.1.c].',
            'Alle inschrijvingen waren op tijd en volledig.',
        ]),
        ('05-gunningsadvies-zaaksysteem.pdf', 'Gunningsadvies zaaksysteem', [
            'Aan: het college van burgemeester en wethouders.',
            'De beoordelingscommissie adviseert de opdracht te gunnen aan de inschrijver met de hoogste totaalscore.',
            'De commissie woog de kwaliteit op toegankelijkheid, open standaarden, migratie en beheer.',
        ]),
    ],
    'pub-afvalkalender': [
        ('01-besluit-inzamelschema-2027.pdf', 'Besluit inzamelschema huishoudelijk afval 2027', [
            'Het college van Zuiddrecht stelt het inzamelschema voor 2027 vast.',
            'Groente-, fruit- en tuinafval en restafval: om de week, op dezelfde dag als in 2026.',
            'Papier en karton: eens per vier weken. Plastic, metaal en drinkpakken: om de week.',
            'Op feestdagen wordt het afval een dag later opgehaald.',
        ]),
    ],
    'pub-parkeerverordening': [
        ('01-verordening-parkeerbelastingen-2027.pdf', 'Verordening parkeerbelastingen Zuiddrecht 2027', [
            'De gemeenteraad van Zuiddrecht stelt de volgende verordening vast.',
            'Artikel 1. Parkeren in zone Centrum kost 2,40 euro per uur. In zone Kerkplein kost het 1,60 euro per uur.',
            'Artikel 2. Een bewonersvergunning kost 96 euro per jaar voor de eerste auto en 192 euro voor de tweede.',
            'Artikel 3. Deze verordening geldt vanaf 1 januari 2027.',
        ]),
    ],
    'pub-terrasregels': [
        ('01-beleidsregels-terrassen-dorpsstraat.pdf', 'Beleidsregels terrassen Dorpsstraat', [
            'Een terras aan de Dorpsstraat laat altijd 1,80 meter vrij voor voetgangers.',
            'Een terras is niet dieper dan de gevel van de zaak breed is.',
            'Een vaste overkapping is een bouwwerk. Daarvoor vraagt u een omgevingsvergunning aan.',
            'Na 23.00 uur is er op het terras geen muziek.',
        ]),
    ],
    'pub-evenement-havenstraat': [
        ('01-beschikking-evenementenvergunning-zomerfeest.pdf', 'Beschikking evenementenvergunning Zomerfeest Havenstraat', [
            'De burgemeester van Zuiddrecht verleent een evenementenvergunning aan de organisatie van het Zomerfeest Havenstraat.',
            'Datum: de tweede zaterdag van juli, van 12.00 tot 22.00 uur.',
            'Voorwaarden: de Havenstraat is afgesloten voor auto\'s, de nooduitgangen blijven vrij en na 22.00 uur is het stil.',
            'De namen van de aanvragers zijn uit deze publicatie weggelaten.',
        ]),
    ],
    'pub-mandaatbesluit': [
        ('01-organisatie-en-mandaatbesluit-2026.pdf', 'Organisatie- en mandaatbesluit gemeente Zuiddrecht 2026', [
            'De gemeente heeft de afdelingen Publiekszaken, Vergunningen, Juridische zaken, Communicatie, Griffie, Financien, HR en Functioneel beheer.',
            'Teamleiders besluiten namens het college over vergunningen waar geen bezwaar tegen is.',
            'De Woo-coordinator besluit namens het college op Woo-verzoeken.',
            'Medewerkers van het KCC mogen namens het college een melding aannemen en doorsturen.',
        ]),
    ],
    'pub-jaarverslag-klachten': [
        ('01-jaarverslag-klachtbehandeling-2025.pdf', 'Jaarverslag klachtbehandeling 2025', [
            'In 2025 ontving de gemeente Zuiddrecht 87 klachten.',
            '61 klachten zijn in een gesprek opgelost. 19 klachten waren gegrond, 7 ongegrond.',
            'De meeste klachten gingen over de bereikbaarheid aan de telefoon en over meldingen die lang open bleven.',
            'Wat wij leerden: wij bellen een melder terug als een melding langer dan twee weken open staat.',
        ]),
    ],
}


def pdf_text(value):
    """Escape a line for a PDF string literal in WinAnsi encoding."""
    data = value.encode('cp1252')
    out = b''
    for byte in data:
        char = bytes([byte])
        if char in (b'(', b')', b'\\'):
            out += b'\\' + char
        else:
            out += char
    return out


def wrap(text, width=88):
    words, lines, line = text.split(), [], ''
    for word in words:
        if line and len(line) + 1 + len(word) > width:
            lines.append(line)
            line = word
        else:
            line = (line + ' ' + word).strip()
    if line:
        lines.append(line)
    return lines


def make_pdf(title, paragraphs):
    lines = [(BANNER, 9), ('', 9), (title, 14), ('', 11)]
    for paragraph in paragraphs:
        lines += [(part, 11) for part in wrap(paragraph)]
        lines.append(('', 11))
    lines += [('', 9), (BANNER, 9)]

    stream = b'BT\n/F1 11 Tf\n56 790 Td\n'
    previous = 11
    for text, size in lines:
        step = int(previous * 1.5)
        stream += b'0 -%d Td\n/F1 %d Tf\n(' % (step, size) + pdf_text(text) + b') Tj\n'
        previous = size
    stream += b'ET\n'

    objects = [
        b'<< /Type /Catalog /Pages 2 0 R >>',
        b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        b'<< /Length %d >>\nstream\n' % len(stream) + stream + b'endstream',
        b'<< /Title (' + pdf_text(title) + b') /Producer (Zuiddrecht example set) >>',
    ]
    out = b'%PDF-1.4\n'
    offsets = []
    for number, body in enumerate(objects, start=1):
        offsets.append(len(out))
        out += b'%d 0 obj\n' % number + body + b'\nendobj\n'
    xref = len(out)
    out += b'xref\n0 %d\n0000000000 65535 f \n' % (len(objects) + 1)
    for offset in offsets:
        out += b'%010d 00000 n \n' % offset
    out += b'trailer\n<< /Size %d /Root 1 0 R /Info 6 0 R >>\nstartxref\n%d\n%%%%EOF\n' % (len(objects) + 1, xref)
    return out


def main():
    with open(FRAGMENT, encoding='utf-8') as handle:
        declared = {o['key'] for o in json.load(handle)['objects'] if o.get('schema') == 'publication'}
    unknown = sorted(set(DOCUMENTS) - declared)
    if unknown:
        sys.exit('These document folders name no publication in the fragment: ' + ', '.join(unknown))
    bare = sorted(declared - set(DOCUMENTS))
    if bare:
        sys.exit('These publications have no documents: ' + ', '.join(bare))

    written = 0
    for key, documents in sorted(DOCUMENTS.items()):
        folder = os.path.join(OUT, key)
        os.makedirs(folder, exist_ok=True)
        for name, title, paragraphs in documents:
            with open(os.path.join(folder, name), 'wb') as handle:
                handle.write(make_pdf(title, paragraphs))
            written += 1
    print('Wrote %d documents for %d publications in %s' % (written, len(DOCUMENTS), os.path.relpath(OUT, ROOT)))


if __name__ == '__main__':
    main()
