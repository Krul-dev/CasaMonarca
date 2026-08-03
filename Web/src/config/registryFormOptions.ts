const ISO_COUNTRY_CODES = `
AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ
CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR
GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO
JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR
MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO
RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV
TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW
`.trim().split(/\s+/)

const regionNames = new Intl.DisplayNames(
  [getAppLocale() === 'en' ? 'en' : 'es-MX'],
  { type: 'region' },
)

export const COUNTRY_OPTIONS = ISO_COUNTRY_CODES
  .map((code) => regionNames.of(code))
  .filter((name): name is string => Boolean(name))
  .sort((left, right) => left.localeCompare(right, getAppLocale() === 'en' ? 'en' : 'es-MX'))

export const CIVIL_STATUS_OPTIONS = [
  { label: t('Single', 'Soltera / Soltero'), value: 'single' },
  { label: t('Married', 'Casada / Casado'), value: 'married' },
  { label: t('Common-law union', 'Unión libre'), value: 'common_law_union' },
  { label: t('Separated', 'Separada / Separado'), value: 'separated' },
  { label: t('Divorced', 'Divorciada / Divorciado'), value: 'divorced' },
  { label: t('Widowed', 'Viuda / Viudo'), value: 'widowed' },
] as const

export const GENDER_OPTIONS = [
  { label: t('Female', 'Femenino'), value: 'female' },
  { label: t('Male', 'Masculino'), value: 'male' },
  { label: t('Non-binary', 'No binario'), value: 'non_binary' },
  { label: 'LGBTQ+', value: 'lgbtq_plus' },
] as const

export const POPULATION_GROUP_OPTIONS = [
  { label: t('Adult (18-59 years)', 'Persona adulta (18-59 años)'), value: 'adult' },
  { label: t('Older adult (60+ years)', 'Persona adulta mayor (60 años o más)'), value: 'older_adult' },
  { label: t('Accompanied girl', 'Niña acompañada'), value: 'accompanied_girl' },
  { label: t('Accompanied boy', 'Niño acompañado'), value: 'accompanied_boy' },
  { label: t('Accompanied adolescent boy', 'Adolescente hombre acompañado'), value: 'accompanied_adolescent_boy' },
  { label: t('Accompanied adolescent girl', 'Adolescente mujer acompañada'), value: 'accompanied_adolescent_girl' },
  { label: t('Unaccompanied child or adolescent', 'Niña, niño o adolescente no acompañado'), value: 'unaccompanied_minor' },
] as const
import { getAppLocale, translate as t } from '../lib/i18n'
