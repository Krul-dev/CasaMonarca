const ISO_COUNTRY_CODES = `
AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ
CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR
GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO
JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR
MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO
RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV
TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW
`.trim().split(/\s+/)

const regionNames = new Intl.DisplayNames(['es-MX'], { type: 'region' })

export const COUNTRY_OPTIONS = ISO_COUNTRY_CODES
  .map((code) => regionNames.of(code))
  .filter((name): name is string => Boolean(name))
  .sort((left, right) => left.localeCompare(right, 'es-MX'))

export const CIVIL_STATUS_OPTIONS = [
  { label: 'Soltera / Soltero', value: 'single' },
  { label: 'Casada / Casado', value: 'married' },
  { label: 'Unión libre', value: 'common_law_union' },
  { label: 'Separada / Separado', value: 'separated' },
  { label: 'Divorciada / Divorciado', value: 'divorced' },
  { label: 'Viuda / Viudo', value: 'widowed' },
] as const

export const GENDER_OPTIONS = [
  { label: 'Femenino', value: 'female' },
  { label: 'Masculino', value: 'male' },
  { label: 'No binario', value: 'non_binary' },
  { label: 'LGBTQ+', value: 'lgbtq_plus' },
] as const

export const POPULATION_GROUP_OPTIONS = [
  { label: 'Adulto (18-59 años)', value: 'adult' },
  { label: 'Adulto mayor (+60 años)', value: 'older_adult' },
  { label: 'Niña acompañada', value: 'accompanied_girl' },
  { label: 'Niño acompañado', value: 'accompanied_boy' },
  { label: 'Adolescente hombre acompañado', value: 'accompanied_adolescent_boy' },
  { label: 'Adolescente mujer acompañada', value: 'accompanied_adolescent_girl' },
  { label: 'NNA no acompañado', value: 'unaccompanied_minor' },
] as const
