path = '/data/sources/stepbible/Tagged-Bibles/TTESV - Tyndale Translation tags for ESV - TyndaleHouse.com STEPBible.org CC BY-NC.txt'
with open(path, encoding='utf-8') as f:
    for i, line in enumerate(f):
        print(repr(line[:300]))
        if i >= 120:
            break
